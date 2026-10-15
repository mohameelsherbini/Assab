<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AdminSession;
use Modules\Admin\Services\AuthService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real processes, independent MariaDB connections, committed fixtures, explicit barriers. */
class AdminFamilyConcurrentEvidenceTest extends TestCase
{
    private string $barriers;
    private array $workers = [];
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MariaDB/InnoDB independent connections required');
        }
        $this->assertSame('testing', app()->environment());
        $this->assertStringStartsWith('s12_family_', DB::connection()->getDatabaseName());
        $this->assertStringContainsString('MariaDB', DB::selectOne('SELECT VERSION() AS version')->version);
        $this->assertSame(0, DB::transactionLevel(), 'Fixtures must be committed outside RefreshDatabase transactions');
        config(['admin.session_families_enabled' => true]);
        foreach (['asab_users', 'personal_access_tokens', 'admin_sessions'] as $table) {
            $engine = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            $this->assertNotNull($engine, 'Run migrations in the disposable schema first');
            $this->assertSame('InnoDB', $engine->engine);
        }
        $this->barriers = sys_get_temp_dir().'/s12-at02-'.Str::uuid();
        mkdir($this->barriers, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        foreach ($this->users as $user) {
            $user->tokens()->delete();
            AdminSession::where('user_id', $user->id)->delete();
            $user->forceDelete();
        }
        if (isset($this->barriers)) {
            foreach (glob($this->barriers.'/*') as $file) {
                unlink($file);
            }
            rmdir($this->barriers);
        }
        parent::tearDown();
    }

    public function test_simultaneous_family_refresh_creates_one_successor_pair(): void
    {
        [$user, $issued] = $this->fixture();
        $a = $this->worker('a', 'refresh', $user, $issued['refreshToken']);
        $this->awaitFile('a.candidate');
        $this->release('a.candidate_go');
        $this->awaitFile('a.held');
        $b = $this->worker('b', 'refresh', $user, $issued['refreshToken'], false);
        $this->awaitFile('b.candidate');
        $this->release('b.candidate_go');
        $this->awaitLockWait('b');
        $this->release('a.held_go');
        $first = $this->workerResult($a, 'a');
        $second = $this->workerResult($b, 'b');
        $this->assertSame('OK', $first['status']);
        $this->assertSame('INVALID_TOKEN', $second['status']);
        $this->assertNotSame($first['connection_id'], $second['connection_id']);
        $this->assertActiveFamily($user, $issued['sessionId'], 1);
        $this->record('family-refresh/refresh', [$first, $second]);
    }

    public function test_refresh_first_rejects_stale_d08_without_revoking_successor(): void
    {
        [$user, $issued] = $this->fixture();
        $d08 = $this->worker('d08', 'logout', $user, $issued['refreshToken'], false);
        $this->awaitFile('d08.candidate');
        $refresh = $this->worker('refresh', 'refresh', $user, $issued['refreshToken']);
        $this->awaitFile('refresh.candidate');
        $this->release('refresh.candidate_go');
        $this->awaitFile('refresh.held');
        $this->release('d08.candidate_go');
        $this->awaitLockWait('d08');
        $this->release('refresh.held_go');
        $rotated = $this->workerResult($refresh, 'refresh');
        $stale = $this->workerResult($d08, 'd08');
        $this->assertSame('OK', $rotated['status']);
        $this->assertSame('INVALID_TOKEN', $stale['status']);
        $this->assertNotSame($rotated['connection_id'], $stale['connection_id']);
        $this->assertActiveFamily($user, $issued['sessionId'], 1);
        $this->record('family-refresh/stale-d08', [$rotated, $stale]);
    }

    public function test_d08_first_prevents_waiting_family_refresh(): void
    {
        [$user, $issued] = $this->fixture();
        $refresh = $this->worker('refresh', 'refresh', $user, $issued['refreshToken'], false);
        $this->awaitFile('refresh.candidate');
        $d08 = $this->worker('d08', 'logout', $user, $issued['refreshToken']);
        $this->awaitFile('d08.candidate');
        $this->release('d08.candidate_go');
        $this->awaitFile('d08.held');
        $this->release('refresh.candidate_go');
        $this->awaitLockWait('refresh');
        $this->release('d08.held_go');
        $revoked = $this->workerResult($d08, 'd08');
        $rejected = $this->workerResult($refresh, 'refresh');
        $this->assertSame('OK', $revoked['status']);
        $this->assertContains($rejected['status'], ['SESSION_EXPIRED', 'INVALID_TOKEN']);
        $this->assertNotSame($revoked['connection_id'], $rejected['connection_id']);
        $session = AdminSession::findOrFail($issued['sessionId']);
        $this->assertNotNull($session->revoked_at);
        $this->assertSame(0, $session->generation);
        $this->assertSame(0, $user->tokens()->where('session_id', $session->id)->count());
        $this->record('family-d08/refresh', [$revoked, $rejected]);
    }

    private function assertActiveFamily(AsabUser $user, string $id, int $generation): void
    {
        $session = AdminSession::findOrFail($id);
        $this->assertNull($session->revoked_at);
        $this->assertSame($generation, $session->generation);
        $this->assertSame(2, $user->tokens()->where('session_id', $id)->count());
        $this->assertNotNull($user->tokens()->find($session->current_access_token_id));
        $this->assertNotNull($user->tokens()->find($session->current_refresh_token_id));
    }

    private function fixture(): array
    {
        $user = AsabUser::create([
            'name' => 'Legacy concurrency', 'email' => Str::uuid().'@example.test',
            'password' => 'OldPassword123!', 'status' => 'active',
        ]);
        $this->users[] = $user;
        return [$user, app(AuthService::class)->issueSessionFamily($user)];
    }

    private function worker(string $name, string $action, AsabUser $user, string $plain, bool $holdRefresh = true): Process
    {
        $code = <<<'WORKER'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['admin.session_families_enabled' => true]);
$dir = getenv('S12_BARRIERS'); $name = getenv('S12_NAME'); $action = getenv('S12_ACTION');
$signal = function ($suffix, $value = 'ready') use ($dir, $name) { file_put_contents($dir.'/'.$name.'.'.$suffix, $value); };
$wait = function ($suffix) use ($dir, $name) {
    $deadline = microtime(true) + 20;
    while (! is_file($dir.'/'.$name.'.'.$suffix)) {
        if (microtime(true) > $deadline) { throw new RuntimeException('Barrier timeout '.$suffix); }
        usleep(10000);
    }
};
$db = Illuminate\Support\Facades\DB::connection();
$db->statement('SET SESSION innodb_lock_wait_timeout = 15');
$connection = (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
$isolation = $db->selectOne('SELECT @@tx_isolation AS level')->level;
$signal('connection', (string) $connection);
$auth = app(Modules\Admin\Services\AuthService::class);
$user = $action === 'refresh' ? null : Modules\Admin\Models\AsabUser::findOrFail(getenv('S12_USER'));
$candidateObserved = false;
$heldObserved = false;
Illuminate\Support\Facades\DB::listen(function ($query) use ($action, &$candidateObserved, &$heldObserved, $signal, $wait) {
    $sql = strtolower($query->sql);
    if (! str_contains($sql, 'asab_users') || ! str_contains($sql, 'select')) { return; }
    if (! $candidateObserved && ! str_contains($sql, 'for update')) {
        $candidateObserved = true; $signal('candidate'); $wait('candidate_go');
    } elseif ($action === 'logout' && getenv('S12_HOLD') === '1'
          && ! $heldObserved && str_contains($sql, 'for update')) {
        $heldObserved = true; $signal('held'); $wait('held_go');
    }
});
try {
    if ($action === 'refresh') {
        $auth->refresh(getenv('S12_TOKEN'), function ($point) use ($signal, $wait) {
            if ($point === 'after_delete_old_tokens' && getenv('S12_HOLD') === '1') { $signal('held'); $wait('held_go'); }
        });
    } elseif ($action === 'revoke') {
        $auth->revokeSession($user, explode('|', getenv('S12_TOKEN'), 2)[0]);
    } elseif ($action === 'logout') {
        // Test-only transaction extends the real token DELETE lock for observation.
        $db->transaction(fn () => $auth->logoutWithRefreshToken(getenv('S12_TOKEN')));
    } else {
        $auth->changePassword($user, 'OldPassword123!', 'NewPassword456!');
    }
    $status = 'OK';
} catch (Modules\Admin\Exceptions\AsabException $e) { $status = $e->errorCode; }
echo json_encode(['status' => $status, 'connection_id' => $connection, 'isolation' => $isolation, 'action' => $action]);
WORKER;
        $worker = new Process([PHP_BINARY, '-r', $code], base_path(), [
            'S12_BARRIERS' => $this->barriers, 'S12_NAME' => $name, 'S12_ACTION' => $action,
            'S12_USER' => $user->id, 'S12_TOKEN' => $plain, 'S12_HOLD' => $holdRefresh ? '1' : '0',
        ]);
        $worker->setTimeout(30);
        $worker->start();
        $this->workers[] = $worker;
        return $worker;
    }

    private function awaitFile(string $suffix): void
    {
        $deadline = microtime(true) + 20;
        while (! is_file($this->barriers.'/'.$suffix)) {
            if (microtime(true) > $deadline) {
                $errors = implode('\n', array_map(fn ($p) => $p->getErrorOutput(), $this->workers));
                $this->fail('Barrier timeout '.$suffix.' '.$errors);
            }
            usleep(10000);
        }
    }

    private function release(string $suffix): void
    {
        file_put_contents($this->barriers.'/'.$suffix, 'go');
    }

    private function awaitLockWait(string $worker, string $expectedTable = 'asab_users'): void
    {
        $this->awaitFile($worker.'.connection');
        $id = (int) file_get_contents($this->barriers.'/'.$worker.'.connection');
        $deadline = microtime(true) + 10;
        do {
            $waiting = DB::selectOne('SELECT trx_query AS query FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = ? AND trx_state = ?', [$id, 'LOCK WAIT']);
            if ($waiting) {
                $this->assertStringContainsString($expectedTable, $waiting->query);
                fwrite(STDOUT, "\nS12_LOCK_WAIT ".json_encode(['connection_id' => $id, 'table' => $expectedTable])."\n");
                $this->assertTrue(true, 'Observed independent connection waiting for row lock');
                return;
            }
            // InnoDB metadata is cached; allow more than 100ms between reads.
            usleep(200000);
        } while (microtime(true) < $deadline);
        $this->fail('Expected an actual InnoDB lock wait for '.$worker.' connection '.$id);
    }

    private function workerResult(Process $worker, string $name): array
    {
        $worker->wait();
        $this->assertTrue($worker->isSuccessful(), $name.': '.$worker->getErrorOutput());
        return json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function record(string $scenario, array $results): void
    {
        $record = ['scenario' => $scenario, 'results' => $results,
            'version' => DB::selectOne('SELECT VERSION() AS version')->version,
            'engines' => DB::select("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('asab_users', 'personal_access_tokens', 'admin_sessions')"),
            'token_count' => $this->users[count($this->users) - 1]->tokens()->count(),
            'observer_connection' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id,
            'barrier' => $scenario === 'refresh/refresh' ? 'both candidates resolved before release' : 'candidate-resolved + user/token-held; observed INNODB_TRX LOCK WAIT'];
        fwrite(STDOUT, "\nS12_FAMILY_CONCURRENCY ".json_encode($record)."\n");
    }
}
