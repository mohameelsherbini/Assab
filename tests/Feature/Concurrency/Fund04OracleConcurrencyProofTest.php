<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Custody\Models\CustodyRequest;
use Modules\Custody\Models\CustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Custody\Services\CustodyRequestService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Targeted MySQL 8.4 / InnoDB Concurrency Proof for FUND-04:
 * 1. Zero financial movements or custody balance change on Accountant Execute/Send.
 * 2. Concurrent receipt confirmations across 2 processes & connections create exactly 1 transaction.
 * 3. Sequential re-confirmation generates zero additional entries.
 */
class Fund04OracleConcurrencyProofTest extends TestCase
{
    private string $barriersDir = '';
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('NOT_RUN: Concurrency proof requires MySQL/InnoDB environment.');
        }

        $dbName = (string) DB::connection()->getDatabaseName();
        if (! preg_match('/^assab_proof_/', $dbName)) {
            throw new \RuntimeException("SAFETY GUARD BLOCKED: Disposable DB pattern required, got '{$dbName}'.");
        }

        $this->barriersDir = sys_get_temp_dir() . '/fund04-proof-' . Str::uuid();
        @mkdir($this->barriersDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->barriersDir && is_dir($this->barriersDir)) {
            @file_put_contents($this->barriersDir . '/start.signal', 'release');
        }

        foreach ($this->workers as $worker) {
            if ($worker instanceof Process && $worker->isRunning()) {
                $worker->stop(2);
            }
        }

        if ($this->barriersDir && is_dir($this->barriersDir)) {
            $files = glob($this->barriersDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->barriersDir);
        }

        parent::tearDown();
    }

    private function createFixtures(): array
    {
        $company = AsabCompany::create([
            'name' => 'FUND-04 Co ' . Str::random(6),
            'plan' => 'Enterprise',
            'status' => 'active',
        ]);

        $brand = AsabBrand::create([
            'company_id' => $company->id,
            'name' => 'FUND-04 Brand ' . Str::random(6),
            'status' => 'active',
        ]);

        $branch = Branch::create([
            'asab_company_id' => $company->id,
            'asab_brand_id' => $brand->id,
            'name' => 'FUND-04 Branch ' . Str::random(6),
            'status' => 'active',
            'opening_time' => '08:00',
            'closing_time' => '23:00',
        ]);

        $manager = BranchManager::create([
            'branch_id' => $branch->id,
            'name' => 'BM ' . Str::random(6),
            'email' => 'bm-' . Str::random(6) . '@fund04.test',
            'phone' => '05' . rand(10000000, 99999999),
            'password' => bcrypt('password'),
            'role' => 'branch_manager',
            'is_active' => true,
            'status' => 'active',
        ]);

        $owner = BrandOwner::create([
            'name' => 'Owner ' . Str::random(6),
            'email' => 'owner-' . Str::random(6) . '@fund04.test',
            'phone' => '05' . rand(10000000, 99999999),
            'password' => bcrypt('password'),
            'is_active' => true,
            'status' => 'active',
        ]);

        $accountant = AsabUser::create([
            'company_id' => $company->id,
            'name' => 'Accountant ' . Str::random(6),
            'email' => 'acc-' . Str::random(6) . '@fund04.test',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $accountant->id,
            'role_key' => 'accountant',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $accountant->id,
            'role_key' => 'accountant',
            'scope' => 'assigned',
            'branch_ids' => [$branch->id],
            'brand_ids' => [$brand->id],
        ]);

        $request = CustodyRequest::create([
            'branch_id' => $branch->id,
            'branch_manager_id' => $manager->id,
            'requested_amount' => 800.00,
            'purpose' => 'branch float',
            'preferred_receipt_method' => 'Cash Handover',
            'status' => 'Approved',
            'approved_at' => now(),
            'approved_by' => $owner->id,
            'approved_by_type' => 'brand_owner',
        ]);

        return [
            'company' => $company,
            'brand' => $brand,
            'branch' => $branch,
            'manager' => $manager,
            'owner' => $owner,
            'accountant' => $accountant,
            'request' => $request,
        ];
    }

    /**
     * Item 1: Accountant Execute/Send does NOT create a financial movement or change custody balance.
     */
    public function test_fund04_item1_accountant_execute_creates_zero_movements_and_preserves_zero_balance(): void
    {
        $f = $this->createFixtures();
        $request = $f['request'];
        $accountant = $f['accountant'];
        $branch = $f['branch'];

        $txBefore = CustodyTransaction::count();
        $ledgerBefore = PersonalLedgerTransaction::count();

        $service = app(CustodyRequestService::class);
        $proof = UploadedFile::fake()->create('proof.pdf', 50, 'application/pdf');

        $executed = $service->executeFundingRequest(
            $request->id,
            $accountant,
            'TRX-REF-800',
            $proof
        );

        $txAfter = CustodyTransaction::count();
        $ledgerAfter = PersonalLedgerTransaction::count();

        $this->assertSame($txBefore, $txAfter, 'Zero custody transactions must be posted on execute');
        $this->assertSame($ledgerBefore, $ledgerAfter, 'Zero personal ledger transactions must be posted on execute');
        $this->assertNotNull($executed->executed_at, 'executed_at must be populated');
        $this->assertSame($accountant->id, $executed->executed_by_user_id);
        $this->assertSame('TRX-REF-800', $executed->transfer_reference);
        $this->assertNotNull($executed->execution_proof_sha256);

        // Verify balance remains exactly 0.00
        $balance = (float) CustodyTransaction::where('branch_id', $branch->id)->sum('amount');
        $this->assertSame(0.0, $balance, 'Branch custody balance must remain 0.00 prior to receipt confirmation');
    }

    /**
     * Item 2: Concurrent confirmations from 2 independent processes & connections create exactly ONE movement.
     */
    public function test_fund04_item2_concurrent_receipt_confirmations_race_condition_creates_exactly_one_movement(): void
    {
        $f = $this->createFixtures();
        $request = $f['request'];
        $accountant = $f['accountant'];
        $manager = $f['manager'];
        $branch = $f['branch'];

        $service = app(CustodyRequestService::class);
        $proof = UploadedFile::fake()->create('proof.pdf', 50, 'application/pdf');
        $service->executeFundingRequest($request->id, $accountant, 'TRX-REF-800', $proof);

        $txBefore = CustodyTransaction::where('related_custody_request_id', $request->id)->count();
        $this->assertSame(0, $txBefore);

        // Spawn Worker 1 and Worker 2
        $p1 = $this->spawnConfirmWorker('w_confirm_1', $request->id, $manager->id);
        $p2 = $this->spawnConfirmWorker('w_confirm_2', $request->id, $manager->id);

        $this->awaitWorkerReady('w_confirm_1');
        $this->awaitWorkerReady('w_confirm_2');

        $conn1 = (int) file_get_contents($this->barriersDir . '/w_confirm_1.conn');
        $conn2 = (int) file_get_contents($this->barriersDir . '/w_confirm_2.conn');

        $this->assertGreaterThan(0, $conn1);
        $this->assertGreaterThan(0, $conn2);
        $this->assertNotSame($conn1, $conn2, 'Workers must use strictly independent MySQL connections');

        // Release barrier simultaneously
        $this->releaseBarrier();

        $r1 = $this->awaitWorkerResult('w_confirm_1');
        $r2 = $this->awaitWorkerResult('w_confirm_2');

        $statuses = [$r1['status'], $r2['status']];
        $this->assertContains('OK', $statuses, 'Exactly one worker must succeed');
        $this->assertContains('ERROR', $statuses, 'Exactly one worker must be rejected');

        $okWorker = $r1['status'] === 'OK' ? $r1 : $r2;
        $errWorker = $r1['status'] === 'ERROR' ? $r1 : $r2;

        $this->assertSame('OK', $okWorker['status']);
        $this->assertSame('ERROR', $errWorker['status']);
        $this->assertStringContainsString('already received', $errWorker['message']);

        // Assert exactly ONE financial transaction was posted
        $txAfter = CustodyTransaction::where('related_custody_request_id', $request->id)->count();
        $this->assertSame(1, $txAfter, 'Exactly 1 custody transaction must exist after concurrent confirmations');

        $tx = CustodyTransaction::where('related_custody_request_id', $request->id)->first();
        $this->assertSame($branch->id, $tx->branch_id);
        $this->assertEquals(800.00, (float) $tx->amount);

        // Assert request state
        $freshReq = CustodyRequest::findOrFail($request->id);
        $this->assertSame('Completed', $freshReq->status);
        $this->assertNotNull($freshReq->received_at);
        $this->assertSame($manager->id, $freshReq->received_by);
    }

    /**
     * Item 3: Sequential re-confirmation is refused and creates zero additional entries.
     */
    public function test_fund04_item3_sequential_reconfirmation_generates_zero_additional_entries(): void
    {
        $f = $this->createFixtures();
        $request = $f['request'];
        $accountant = $f['accountant'];
        $manager = $f['manager'];

        $service = app(CustodyRequestService::class);
        $proof = UploadedFile::fake()->create('proof.pdf', 50, 'application/pdf');
        $service->executeFundingRequest($request->id, $accountant, 'TRX-REF-800', $proof);

        // First confirmation succeeds
        $service->confirmReceipt($request->id, $manager);

        $txCountInitial = CustodyTransaction::where('related_custody_request_id', $request->id)->count();
        $this->assertSame(1, $txCountInitial);

        // Sequential re-confirmation attempt
        $rejected = false;
        try {
            $service->confirmReceipt($request->id, $manager);
        } catch (\RuntimeException $e) {
            $rejected = true;
            $this->assertStringContainsString('already received', $e->getMessage());
        }

        $this->assertTrue($rejected, 'Sequential re-confirmation must be rejected with RuntimeException');

        $txCountAfter = CustodyTransaction::where('related_custody_request_id', $request->id)->count();
        $this->assertSame(1, $txCountAfter, 'Zero additional transactions must be generated by re-confirmation');
    }

    private function spawnConfirmWorker(string $workerId, string $requestId, string $managerId): Process
    {
        $code = <<<'PHP'
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$barrierDir = getenv('FUND04_BARRIER_DIR');
$workerId = getenv('FUND04_WORKER_ID');
$requestId = getenv('FUND04_REQUEST_ID');
$managerId = getenv('FUND04_MANAGER_ID');

try {
    $expectedConn = getenv('DB_CONNECTION') ?: 'mysql';
    $expectedHost = getenv('DB_HOST') ?: '127.0.0.1';
    $expectedPort = getenv('DB_PORT') ?: '3306';
    $expectedDb   = getenv('DB_DATABASE');
    $expectedUser = getenv('DB_USERNAME');
    $expectedPass = getenv('DB_PASSWORD');

    config(["database.default" => $expectedConn]);
    if ($expectedDb) {
        config(["database.connections.{$expectedConn}.database" => $expectedDb]);
    }
    if ($expectedHost) {
        config(["database.connections.{$expectedConn}.host" => $expectedHost]);
    }
    if ($expectedPort) {
        config(["database.connections.{$expectedConn}.port" => $expectedPort]);
    }
    if ($expectedUser) {
        config(["database.connections.{$expectedConn}.username" => $expectedUser]);
    }
    if ($expectedPass !== false) {
        config(["database.connections.{$expectedConn}.password" => $expectedPass]);
    }
    DB::purge();
    DB::reconnect();

    $connId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
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
while (!is_file($barrierDir . '/start.signal')) {
    if (microtime(true) > $deadline) {
        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode([
            'status' => 'TIMEOUT',
            'code' => 'BARRIER_TIMEOUT',
            'message' => 'Worker timed out waiting for barrier',
            'conn' => $connId,
        ]));
        exit(1);
    }
    usleep(5000);
}

// Execute confirmReceipt
try {
    $manager = Modules\BranchManagers\Models\BranchManager::findOrFail($managerId);
    $res = app(Modules\Custody\Services\CustodyRequestService::class)->confirmReceipt($requestId, $manager);
    file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode([
        'status' => 'OK',
        'res' => $res->id,
        'conn' => $connId,
    ]));
} catch (\Throwable $e) {
    file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode([
        'status' => 'ERROR',
        'code' => get_class($e),
        'message' => $e->getMessage(),
        'conn' => $connId,
    ]));
}
PHP;

        $defaultConn = config('database.default', 'mysql');
        $connConfig = config("database.connections.{$defaultConn}", []);
        $dbEnv = [
            'DB_CONNECTION' => (string) $defaultConn,
            'DB_HOST' => (string) ($connConfig['host'] ?? env('DB_HOST', '127.0.0.1')),
            'DB_PORT' => (string) ($connConfig['port'] ?? env('DB_PORT', '3306')),
            'DB_DATABASE' => (string) ($connConfig['database'] ?? env('DB_DATABASE')),
            'DB_USERNAME' => (string) ($connConfig['username'] ?? env('DB_USERNAME')),
            'DB_PASSWORD' => (string) ($connConfig['password'] ?? env('DB_PASSWORD')),
        ];

        $process = new Process([PHP_BINARY, '-r', $code], base_path(), array_merge([
            'FUND04_BARRIER_DIR' => $this->barriersDir,
            'FUND04_WORKER_ID' => $workerId,
            'FUND04_REQUEST_ID' => $requestId,
            'FUND04_MANAGER_ID' => $managerId,
        ], $dbEnv));

        $process->setTimeout(30);
        $process->start();
        $this->workers[$workerId] = $process;

        return $process;
    }

    private function awaitWorkerReady(string $workerId): void
    {
        $deadline = microtime(true) + 15;
        $connFile = $this->barriersDir . '/' . $workerId . '.conn';
        $resultFile = $this->barriersDir . '/' . $workerId . '.result';

        while (! is_file($connFile)) {
            if (is_file($resultFile)) {
                $content = json_decode(file_get_contents($resultFile), true);
                $this->fail("Worker {$workerId} failed during bootstrap: " . json_encode($content));
            }
            if (microtime(true) > $deadline) {
                $process = $this->workers[$workerId] ?? null;
                $err = $process instanceof Process ? $process->getErrorOutput() : 'N/A';
                $out = $process instanceof Process ? $process->getOutput() : 'N/A';
                $this->fail("Worker {$workerId} timed out awaiting bootstrap. Output: {$out} | Error: {$err}");
            }
            usleep(10000);
        }
    }

    private function releaseBarrier(): void
    {
        file_put_contents($this->barriersDir . '/start.signal', 'release');
    }

    private function awaitWorkerResult(string $workerId): array
    {
        $deadline = microtime(true) + 30;
        $file = $this->barriersDir . '/' . $workerId . '.result';
        $process = $this->workers[$workerId] ?? null;

        while (! is_file($file)) {
            if ($process instanceof Process && ! $process->isRunning()) {
                $err = $process->getErrorOutput();
                $out = $process->getOutput();
                $this->fail("Worker {$workerId} terminated prematurely (exit code {$process->getExitCode()}): Output: {$out} | Error: {$err}");
            }
            if (microtime(true) > $deadline) {
                $err = $process instanceof Process ? $process->getErrorOutput() : 'N/A';
                $out = $process instanceof Process ? $process->getOutput() : 'N/A';
                $this->fail("Worker {$workerId} timed out waiting for result file. Output: {$out} | Error: {$err}");
            }
            usleep(10000);
        }

        return json_decode(file_get_contents($file), true) ?? [];
    }
}
