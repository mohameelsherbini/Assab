<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Services\ShiftNotWorkedService;
use Modules\Shift\Services\ShiftReportMutationGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ASSAB ERP — Wave 1–2 Final Backend Closure.
 *
 * BE-12 / PKG-67 Targeted Oracle MySQL 8.4 / InnoDB Concurrency Proof.
 *
 * Verified Invariants:
 * 1. Oracle MySQL 8.4 Community Server runtime (InnoDB, REPEATABLE-READ, NOT MariaDB).
 * 2. Shift timing integrity:
 *    - Rejection of future shift dates (SHIFT_NOT_WORKED_FUTURE_ASSIGNMENT).
 *    - Rejection of open/ongoing shift periods (SHIFT_NOT_WORKED_PERIOD_OPEN).
 *    - Allowed only after shift period has elapsed.
 * 3. Atomic status (canceled) and audit trail (marked_not_worked) persistence with zero financial side-effects.
 * 4. Multi-process concurrency across two independent OS processes with distinct CONNECTION_ID():
 *    - Exactly one worker succeeds; competing worker rejected with 409 Conflict.
 *    - Exactly 1 cancellation, exactly 1 audit history record, zero duplicate rows.
 * 5. Serialization and mutual exclusion with mutation guard (lockEditable / assertMutable).
 */
class Pkg67OracleConcurrencyProofTest extends TestCase
{
    private array $workers = [];
    private ?string $barriersDir = null;
    private array $createdCompanyIds = [];
    private array $createdBranchIds = [];
    private array $createdManagerIds = [];
    private array $createdCashierIds = [];
    private array $createdSlotIds = [];
    private array $createdShiftIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $targetDb = env('DB_DATABASE', 'assab_proof_pkg67_oracle');
        $targetPort = (int) (env('DB_PORT') ?: (env('MYSQL_TEST_PORT') ?: 3306));
        $targetHost = env('DB_HOST') ?: (env('MYSQL_TEST_HOST') ?: '127.0.0.1');
        $targetUser = env('DB_USERNAME') ?: (env('MYSQL_TEST_USER') ?: 'root');
        $targetPass = env('DB_PASSWORD') ?: (env('MYSQL_TEST_PASSWORD') ?: '');

        config(['database.default' => 'mysql']);
        config(['database.connections.mysql.host' => $targetHost]);
        config(['database.connections.mysql.port' => $targetPort]);
        config(['database.connections.mysql.database' => $targetDb]);
        config(['database.connections.mysql.username' => $targetUser]);
        config(['database.connections.mysql.password' => $targetPass]);
        config(['features.shift_did_not_work' => true]);

        DB::purge('mysql');
        DB::reconnect('mysql');

        // Strict Fail-Closed Environment Guard: Prevent execution against non-disposable or non-local DB
        $dbName = (string) DB::connection('mysql')->getDatabaseName();
        if (! preg_match('/^assab_proof_pkg67_oracle$/', $dbName) && ! preg_match('/^assab_proof_/', $dbName)) {
            throw new \RuntimeException("SAFETY GUARD BLOCKED: Targeted tests are strictly restricted to disposable schema pattern 'assab_proof_*', got '{$dbName}'.");
        }

        $host = (string) config('database.connections.mysql.host');
        $allowedHosts = ['127.0.0.1', 'localhost', '::1'];
        if (! in_array(strtolower($host), $allowedHosts, true)) {
            throw new \RuntimeException("SAFETY GUARD BLOCKED: Targeted tests can only run against a local loopback host, got '{$host}'.");
        }

        foreach (['prod', 'production', 'staging', 'live', 'coolify', 'railway', 'hostinger', 'shared'] as $blocked) {
            if (str_contains(strtolower($host), $blocked) || str_contains(strtolower($dbName), $blocked)) {
                throw new \RuntimeException("SAFETY GUARD BLOCKED: Host or database name contains forbidden keyword '{$blocked}'.");
            }
        }

        $this->createdCompanyIds = [];
        $this->createdBranchIds = [];
        $this->createdManagerIds = [];
        $this->createdCashierIds = [];
        $this->createdSlotIds = [];
        $this->createdShiftIds = [];

        $this->barriersDir = sys_get_temp_dir().'/pkg67-oracle-concurr-'.Str::uuid();
        @mkdir($this->barriersDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->barriersDir && is_dir($this->barriersDir)) {
            @file_put_contents($this->barriersDir.'/start.signal', 'release');
            @file_put_contents($this->barriersDir.'/mark.signal', 'release');
            @file_put_contents($this->barriersDir.'/mutate.signal', 'release');
        }

        foreach ($this->workers as $worker) {
            if ($worker instanceof Process && $worker->isRunning()) {
                $worker->stop(1);
            }
        }
        $this->workers = [];

        if ($this->barriersDir && is_dir($this->barriersDir)) {
            foreach (glob($this->barriersDir.'/*') as $f) {
                @unlink($f);
            }
            @rmdir($this->barriersDir);
            $this->barriersDir = null;
        }

        // Guaranteed cleanup in reverse foreign key order
        try {
            if (! empty($this->createdShiftIds)) {
                DB::table('cashier_shift_history')->whereIn('cashier_shift_id', $this->createdShiftIds)->delete();
                DB::table('cashier_shifts')->whereIn('id', $this->createdShiftIds)->delete();
                $this->createdShiftIds = [];
            }

            if (! empty($this->createdSlotIds)) {
                DB::table('shifts')->whereIn('id', $this->createdSlotIds)->delete();
                $this->createdSlotIds = [];
            }

            if (! empty($this->createdCashierIds)) {
                DB::table('cashiers')->whereIn('id', $this->createdCashierIds)->delete();
                $this->createdCashierIds = [];
            }

            if (! empty($this->createdManagerIds)) {
                DB::table('branch_managers')->whereIn('id', $this->createdManagerIds)->delete();
                $this->createdManagerIds = [];
            }

            if (! empty($this->createdBranchIds)) {
                DB::table('branches')->whereIn('id', $this->createdBranchIds)->delete();
                $this->createdBranchIds = [];
            }

            if (! empty($this->createdCompanyIds)) {
                DB::table('asab_companies')->whereIn('id', $this->createdCompanyIds)->delete();
                $this->createdCompanyIds = [];
            }
        } finally {
            parent::tearDown();
        }
    }

    private function assertFixtureVisibleFromIndependentConnection(string $table, string $idColumn, string $idValue): void
    {
        $host = (string) config('database.connections.mysql.host', '127.0.0.1');
        $port = (int) config('database.connections.mysql.port', 3306);
        $db = (string) config('database.connections.mysql.database', 'assab_proof_pkg67_oracle');
        $user = (string) config('database.connections.mysql.username', 'root');
        $pass = (string) config('database.connections.mysql.password', '');

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
        $pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM `{$table}` WHERE `{$idColumn}` = ?");
        $stmt->execute([$idValue]);
        $row = $stmt->fetch();

        $this->assertGreaterThan(
            0,
            (int) ($row['c'] ?? 0),
            "Fixture row in '{$table}' ({$idColumn}={$idValue}) must be physically committed and visible from an independent PDO connection before spawning workers."
        );
    }

    private function createShiftFixture(string $date, string $startTime, string $endTime): array
    {
        $company = AsabCompany::create([
            'name' => 'Oracle Proof Co '.Str::random(6),
            'subdomain' => 'proof-'.Str::random(8),
            'status' => 'active',
        ]);
        $this->createdCompanyIds[] = $company->id;

        $branch = Branch::create([
            'name' => 'Oracle Proof Branch '.Str::random(6),
            'location' => 'Riyadh',
            'city' => 'Riyadh',
            'asab_company_id' => $company->id,
            'status' => 'active',
        ]);
        $this->createdBranchIds[] = $branch->id;

        $manager = BranchManager::create([
            'branch_id' => $branch->id,
            'name' => 'Manager '.Str::random(6),
            'email' => 'bm-'.Str::random(8).'@proof.test',
            'phone' => '05'.rand(10000000, 99999999),
            'password' => bcrypt('secret'),
            'is_active' => true,
            'status' => 'active',
        ]);
        $this->createdManagerIds[] = $manager->id;

        $cashier = Cashier::create([
            'branch_id' => $branch->id,
            'name' => 'Cashier '.Str::random(6),
            'email' => 'cashier-'.Str::random(8).'@proof.test',
            'password' => bcrypt('secret'),
            'status' => 'active',
            'created_by' => $manager->id,
        ]);
        $this->createdCashierIds[] = $cashier->id;

        $slot = Shift::create([
            'name' => 'Shift '.Str::random(6),
            'branch_id' => $branch->id,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);
        $this->createdSlotIds[] = $slot->id;

        $shift = CashierShift::create([
            'cashier_id' => $cashier->id,
            'shift_id' => $slot->id,
            'shift_date' => $date,
            'status' => ShiftStatus::NOT_STARTED,
            'opening_balance' => '0.00',
            'closing_balance' => '0.00',
            'expected_balance' => '0.00',
            'variance' => '0.00',
            'total_sales' => '0.00',
            'net_sales' => '0.00',
            'vat_amount' => '0.00',
            'cash_collected' => '0.00',
            'card_payments' => '0.00',
        ]);
        $this->createdShiftIds[] = $shift->id;

        $this->assertSame(0, DB::transactionLevel(), 'Fixtures must be committed with transactionLevel=0');
        $this->assertFixtureVisibleFromIndependentConnection('cashier_shifts', 'id', (string) $shift->id);

        return compact('company', 'branch', 'manager', 'cashier', 'slot', 'shift');
    }

    private function writeWorkerScript(): string
    {
        $scriptPath = $this->barriersDir.'/worker.php';
        $scriptContent = <<<'PHP'
<?php

$baseDir = getenv('APP_BASE_PATH') ?: getcwd();
require $baseDir . '/vendor/autoload.php';
$app = require_once $baseDir . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$barrierDir = getenv('BARRIER_DIR');
$workerId = getenv('WORKER_ID');
$action = getenv('ACTION');
$signalFile = getenv('SIGNAL_FILE') ?: 'start.signal';

$connId = null;
try {
    $expectedDb = getenv('DB_DATABASE') ?: 'assab_proof_pkg67_oracle';
    $expectedHost = getenv('DB_HOST') ?: '127.0.0.1';
    $expectedPort = (int) (getenv('DB_PORT') ?: (getenv('MYSQL_TEST_PORT') ?: 3306));
    $expectedUser = getenv('DB_USERNAME') ?: 'root';
    $expectedPass = getenv('DB_PASSWORD') ?: '';

    config(['database.default' => 'mysql']);
    config(['database.connections.mysql.database' => $expectedDb]);
    config(['database.connections.mysql.host' => $expectedHost]);
    config(['database.connections.mysql.port' => $expectedPort]);
    config(['database.connections.mysql.username' => $expectedUser]);
    config(['database.connections.mysql.password' => $expectedPass]);
    config(['features.shift_did_not_work' => true]);

    DB::purge('mysql');
    DB::reconnect('mysql');

    $connId = (int) DB::connection('mysql')->selectOne('SELECT CONNECTION_ID() AS id')->id;
    file_put_contents($barrierDir . '/' . $workerId . '.conn', (string) $connId);
} catch (\Throwable $e) {
    file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode([
        'status' => 'EXCEPTION',
        'code' => get_class($e),
        'message' => 'Connection failed: ' . $e->getMessage(),
        'conn' => null,
    ]));
    exit(1);
}

// Await start barrier signal
$deadline = microtime(true) + 30;
while (!is_file($barrierDir . '/' . $signalFile)) {
    if (microtime(true) > $deadline) {
        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode([
            'status' => 'TIMEOUT',
            'code' => 'BARRIER_TIMEOUT',
            'message' => 'Worker timed out waiting for barrier signal: ' . $signalFile,
            'conn' => $connId,
        ]));
        exit(1);
    }
    usleep(5000);
}

// Execute the concurrent action
try {
    if ($action === 'mark_not_worked') {
        $shiftId = getenv('SHIFT_ID');
        $managerId = getenv('MANAGER_ID');
        $reason = getenv('REASON') ?: 'Not worked';

        $shift = Modules\Shift\Models\CashierShift::findOrFail($shiftId);
        $manager = Modules\BranchManagers\Models\BranchManager::findOrFail($managerId);

        $service = app(Modules\Shift\Services\ShiftNotWorkedService::class);
        $res = $service->mark($shift, $manager, $reason);

        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode([
            'status' => 'OK',
            'shift_status' => $res->status->value ?? (string) $res->status,
            'conn' => $connId,
        ]));
    } elseif ($action === 'mutation_guard') {
        $shiftId = getenv('SHIFT_ID');
        $managerId = getenv('MANAGER_ID');

        $shift = Modules\Shift\Models\CashierShift::findOrFail($shiftId);
        $manager = Modules\BranchManagers\Models\BranchManager::findOrFail($managerId);

        DB::transaction(function () use ($shift, $manager) {
            $locked = app(Modules\Shift\Services\ShiftReportMutationGuard::class)->lockEditable($shift, $manager);
            $locked->touch();
        });

        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode([
            'status' => 'OK',
            'conn' => $connId,
        ]));
    } else {
        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode([
            'status' => 'EXCEPTION',
            'code' => 'UNKNOWN_ACTION',
            'message' => 'Unknown action: ' . $action,
            'conn' => $connId,
        ]));
    }
} catch (\Symfony\Component\HttpKernel\Exception\HttpException $h) {
    file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode([
        'status' => 'ERROR',
        'code' => $h->getMessage(),
        'message' => $h->getMessage(),
        'http_status' => $h->getStatusCode(),
        'conn' => $connId,
    ]));
} catch (\Throwable $t) {
    file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode([
        'status' => 'EXCEPTION',
        'code' => get_class($t),
        'message' => $t->getMessage(),
        'conn' => $connId,
    ]));
}
PHP;

        file_put_contents($scriptPath, $scriptContent);
        return $scriptPath;
    }

    private function spawnWorker(string $workerId, string $action, array $envParams = []): Process
    {
        $scriptPath = $this->writeWorkerScript();
        $dbEnv = array_merge([
            'APP_BASE_PATH' => base_path(),
            'BARRIER_DIR' => $this->barriersDir,
            'WORKER_ID' => $workerId,
            'ACTION' => $action,
            'SIGNAL_FILE' => $envParams['SIGNAL_FILE'] ?? 'start.signal',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => config('database.connections.mysql.host', '127.0.0.1'),
            'DB_PORT' => (string) config('database.connections.mysql.port', 3306),
            'DB_DATABASE' => config('database.connections.mysql.database', 'assab_proof_pkg67_oracle'),
            'DB_USERNAME' => config('database.connections.mysql.username', 'root'),
            'DB_PASSWORD' => (string) config('database.connections.mysql.password', ''),
            'FEATURE_SHIFT_DID_NOT_WORK' => 'true',
        ], $envParams);

        $process = new Process([
            PHP_BINARY,
            '-d', 'memory_limit=512M',
            $scriptPath,
        ], base_path(), $dbEnv);

        $process->start();
        $this->workers[$workerId] = $process;

        // Await worker connection handshake
        $deadline = microtime(true) + 15;
        $connFile = $this->barriersDir.'/'.$workerId.'.conn';
        $resultFile = $this->barriersDir.'/'.$workerId.'.result';

        while (! is_file($connFile)) {
            if (is_file($resultFile)) {
                $res = json_decode((string) file_get_contents($resultFile), true);
                throw new \RuntimeException("Worker '{$workerId}' aborted early: ".json_encode($res));
            }
            if (microtime(true) > $deadline) {
                throw new \RuntimeException("Worker '{$workerId}' failed to connect within 15s. Process output: ".$process->getOutput().' | Err: '.$process->getErrorOutput());
            }
            usleep(10000);
        }

        return $process;
    }

    private function releaseSignal(): void
    {
        file_put_contents($this->barriersDir.'/start.signal', 'go');
    }

    private function collectWorkerResult(string $workerId): array
    {
        $resultFile = $this->barriersDir.'/'.$workerId.'.result';
        $deadline = microtime(true) + 30;

        while (! is_file($resultFile)) {
            if (microtime(true) > $deadline) {
                $process = $this->workers[$workerId] ?? null;
                $out = $process ? $process->getOutput() : '';
                $err = $process ? $process->getErrorOutput() : '';
                throw new \RuntimeException("Timeout waiting for result of worker '{$workerId}'. Out: {$out} | Err: {$err}");
            }
            usleep(10000);
        }

        $content = file_get_contents($resultFile);
        $data = json_decode((string) $content, true);
        if (! is_array($data)) {
            throw new \RuntimeException("Corrupt result from worker '{$workerId}': '{$content}'");
        }

        return $data;
    }

    // =========================================================================
    // Test 1: Oracle MySQL Server Environment and Isolation Verification
    // =========================================================================
    public function test_01_oracle_mysql_server_environment_and_isolation(): void
    {
        $versionRow = DB::connection('mysql')->selectOne('SELECT VERSION() AS v, @@default_storage_engine AS engine, @@transaction_isolation AS isolation');
        $version = (string) $versionRow->v;
        $engine = (string) $versionRow->engine;
        $isolation = (string) $versionRow->isolation;

        // Oracle MySQL Community Server 8.4 verification
        $this->assertStringContainsString('8.4.', $version, "Must be running Oracle MySQL 8.4.x, got: {$version}");
        $this->assertStringNotContainsStringIgnoringCase('mariadb', $version, "Must NOT be MariaDB, got: {$version}");
        $this->assertSame('InnoDB', $engine, "Default storage engine must be InnoDB, got: {$engine}");
        $this->assertSame('REPEATABLE-READ', $isolation, "Transaction isolation must be REPEATABLE-READ, got: {$isolation}");
    }

    // =========================================================================
    // Test 2: Shift Timing Integrity — Future Assignment Rejected
    // =========================================================================
    public function test_02_shift_timing_integrity_future_assignment_rejected(): void
    {
        $futureDate = today()->addDays(2)->toDateString();
        $f = $this->createShiftFixture($futureDate, '08:00:00', '16:00:00');

        $service = app(ShiftNotWorkedService::class);

        try {
            $service->mark($f['shift'], $f['manager'], 'Closed for deep cleaning');
            $this->fail('Expected ConflictHttpException SHIFT_NOT_WORKED_FUTURE_ASSIGNMENT');
        } catch (ConflictHttpException $e) {
            $this->assertSame('SHIFT_NOT_WORKED_FUTURE_ASSIGNMENT', $e->getMessage());
        }

        // Verify shift status unchanged and 0 audit history rows created
        $this->assertSame(ShiftStatus::NOT_STARTED, $f['shift']->fresh()->status);
        $this->assertSame(0, $f['shift']->notWorkedHistory()->count());
    }

    // =========================================================================
    // Test 3: Shift Timing Integrity — Open Period Rejected
    // =========================================================================
    public function test_03_shift_timing_integrity_period_open_rejected(): void
    {
        $todayDate = today()->toDateString();
        // Shift scheduled to end 2 hours in the future
        $startTime = now()->subHour()->format('H:i:s');
        $endTime = now()->addHours(2)->format('H:i:s');
        $f = $this->createShiftFixture($todayDate, $startTime, $endTime);

        $service = app(ShiftNotWorkedService::class);

        try {
            $service->mark($f['shift'], $f['manager'], 'Closed early');
            $this->fail('Expected ConflictHttpException SHIFT_NOT_WORKED_PERIOD_OPEN');
        } catch (ConflictHttpException $e) {
            $this->assertSame('SHIFT_NOT_WORKED_PERIOD_OPEN', $e->getMessage());
        }

        // Verify shift status unchanged and 0 audit history rows created
        $this->assertSame(ShiftStatus::NOT_STARTED, $f['shift']->fresh()->status);
        $this->assertSame(0, $f['shift']->notWorkedHistory()->count());
    }

    // =========================================================================
    // Test 4: Elapsed Period Allowed and Atomically Marked with Audit Trail
    // =========================================================================
    public function test_04_shift_timing_integrity_elapsed_period_allowed_and_atomic(): void
    {
        $pastDate = today()->subDay()->toDateString();
        $f = $this->createShiftFixture($pastDate, '08:00:00', '16:00:00');

        $reason = 'المطعم كان مغلقاً للصيانة الدورية';
        $service = app(ShiftNotWorkedService::class);
        $result = $service->mark($f['shift'], $f['manager'], $reason);

        // Verify atomic status transition
        $this->assertSame(ShiftStatus::CANCELED, $result->status);
        $this->assertSame(ShiftStatus::CANCELED, $f['shift']->fresh()->status);

        // Verify explicit audit fact in cashier_shift_history
        $history = $f['shift']->notWorkedHistory()->get();
        $this->assertCount(1, $history);
        $fact = $history->first();

        $this->assertSame('marked_not_worked', $fact->action);
        $this->assertSame((string) $f['manager']->id, (string) $fact->performed_by);
        $this->assertSame('branch_manager', $fact->performed_by_type);
        $this->assertSame($reason, $fact->notes);

        $newValue = is_string($fact->new_value) ? json_decode($fact->new_value, true) : $fact->new_value;
        $this->assertSame('not_worked', $newValue['outcome'] ?? null);
        $this->assertSame(ShiftStatus::CANCELED->value, $newValue['status'] ?? null);
        $this->assertSame($reason, $newValue['reason'] ?? null);

        // Verify zero financial side effects in operations, aggregates, or custody
        $this->assertSame(0, DB::table('asab_operations')->where('source_id', $f['shift']->id)->count());
        $this->assertSame(0, DB::table('shift_report_aggregates')->where('source_id', $f['shift']->id)->count());
        $this->assertSame(0, DB::table('cashier_custody_transactions')->where('related_shift_id', $f['shift']->id)->count());
    }

    // =========================================================================
    // Test 5: Real Multi-Process Concurrency Race Across Two Independent Connections
    // =========================================================================
    public function test_05_multi_process_concurrency_race_two_independent_connections(): void
    {
        $pastDate = today()->subDay()->toDateString();
        $f = $this->createShiftFixture($pastDate, '08:00:00', '16:00:00');

        // Spawn Worker 1 and Worker 2
        $this->spawnWorker('worker_1', 'mark_not_worked', [
            'SHIFT_ID' => (string) $f['shift']->id,
            'MANAGER_ID' => (string) $f['manager']->id,
            'REASON' => 'Concurrent race attempt Worker 1',
        ]);

        $this->spawnWorker('worker_2', 'mark_not_worked', [
            'SHIFT_ID' => (string) $f['shift']->id,
            'MANAGER_ID' => (string) $f['manager']->id,
            'REASON' => 'Concurrent race attempt Worker 2',
        ]);

        // Verify distinct PDO connection IDs
        $conn1 = (int) file_get_contents($this->barriersDir.'/worker_1.conn');
        $conn2 = (int) file_get_contents($this->barriersDir.'/worker_2.conn');
        $this->assertGreaterThan(0, $conn1);
        $this->assertGreaterThan(0, $conn2);
        $this->assertNotSame($conn1, $conn2, "Workers must run on separate PDO connections: {$conn1} vs {$conn2}");

        // Release barrier signal simultaneously
        $this->releaseSignal();

        // Collect results
        $res1 = $this->collectWorkerResult('worker_1');
        $res2 = $this->collectWorkerResult('worker_2');

        $statuses = [$res1['status'], $res2['status']];
        $this->assertContains('OK', $statuses, 'At least one worker must succeed');
        $this->assertContains('ERROR', $statuses, 'Competing worker must be rejected with domain exception');

        $successWorker = $res1['status'] === 'OK' ? $res1 : $res2;
        $failedWorker = $res1['status'] === 'ERROR' ? $res1 : $res2;

        $this->assertSame(ShiftStatus::CANCELED->value, $successWorker['shift_status']);
        $this->assertSame('SHIFT_MARKED_NOT_WORKED', $failedWorker['code']);
        $this->assertSame(409, $failedWorker['http_status']);

        // Assert database state: exactly 1 cancellation, exactly 1 history row
        $this->assertSame(ShiftStatus::CANCELED, $f['shift']->fresh()->status);
        $this->assertSame(1, $f['shift']->notWorkedHistory()->count(), 'Exactly one audit history entry must exist');
        $this->assertSame(1, DB::table('cashier_shift_history')->where('cashier_shift_id', $f['shift']->id)->count());
    }

    // =========================================================================
    // Test 6: Multi-Process Concurrency: Competing Mutation Guard Under Lock
    // =========================================================================
    public function test_06_multi_process_concurrency_competing_mutation_guard_under_lock(): void
    {
        $pastDate = today()->subDay()->toDateString();
        $f = $this->createShiftFixture($pastDate, '08:00:00', '16:00:00');

        // Worker 1 marks shift not worked (using mark.signal)
        $this->spawnWorker('worker_mark', 'mark_not_worked', [
            'SHIFT_ID' => (string) $f['shift']->id,
            'MANAGER_ID' => (string) $f['manager']->id,
            'REASON' => 'Closed due to maintenance',
            'SIGNAL_FILE' => 'mark.signal',
        ]);

        // Worker 2 attempts mutation / review via lockEditable (using mutate.signal)
        $this->spawnWorker('worker_mutate', 'mutation_guard', [
            'SHIFT_ID' => (string) $f['shift']->id,
            'MANAGER_ID' => (string) $f['manager']->id,
            'SIGNAL_FILE' => 'mutate.signal',
        ]);

        $conn1 = (int) file_get_contents($this->barriersDir.'/worker_mark.conn');
        $conn2 = (int) file_get_contents($this->barriersDir.'/worker_mutate.conn');
        $this->assertGreaterThan(0, $conn1);
        $this->assertGreaterThan(0, $conn2);
        $this->assertNotSame($conn1, $conn2, "Workers must run on separate connections: {$conn1} vs {$conn2}");

        // Phase 1: Release mark worker first to establish terminal not-worked fact
        file_put_contents($this->barriersDir.'/mark.signal', 'go');
        $resMark = $this->collectWorkerResult('worker_mark');
        $this->assertSame('OK', $resMark['status'], 'Mark worker must succeed');

        // Phase 2: Release competing mutation worker on the independent connection
        file_put_contents($this->barriersDir.'/mutate.signal', 'go');
        $resMutate = $this->collectWorkerResult('worker_mutate');

        // Mutation worker must be rejected under lockEditable guard with SHIFT_MARKED_NOT_WORKED (HTTP 409)
        $this->assertSame('ERROR', $resMutate['status'], 'Mutation worker must be rejected under lockEditable guard');
        $this->assertSame('SHIFT_MARKED_NOT_WORKED', $resMutate['code']);
        $this->assertSame(409, $resMutate['http_status']);

        // Database assertion: exactly 1 terminal canceled state, exactly 1 audit history fact
        $this->assertSame(ShiftStatus::CANCELED, $f['shift']->fresh()->status);
        $this->assertSame(1, $f['shift']->notWorkedHistory()->count());
    }
}
