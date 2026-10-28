<?php

declare(strict_types=1);

require_once __DIR__.'/mysql_lock_wait_observer.php';

function lockProbePdo(): PDO
{
    $database = (string) getenv('DB_DATABASE');
    $dsn = 'mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.$database.';charset=utf8mb4';

    return new PDO($dsn, (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function lockProbeConnection(PDO $pdo): array
{
    $connection = $pdo->query('SELECT CONNECTION_ID() AS connection_id, PS_THREAD_ID(CONNECTION_ID()) AS thread_id')->fetch();
    if (! is_array($connection) || (int) $connection['connection_id'] < 1 || (int) $connection['thread_id'] < 1) {
        throw new RuntimeException('PS_THREAD_ID did not map the active connection.');
    }

    return [
        'connection_id' => (int) $connection['connection_id'],
        'thread_id' => (int) $connection['thread_id'],
    ];
}

function runLockProbeWaiter(string $marker): never
{
    try {
        $pdo = lockProbePdo();
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 30');
        $identity = lockProbeConnection($pdo);
        file_put_contents($marker, json_encode($identity, JSON_THROW_ON_ERROR), LOCK_EX);
        $pdo->beginTransaction();
        $pdo->exec('UPDATE assab_lock_observation_probe SET value = value + 1 WHERE id = 1');
        $pdo->commit();
        file_put_contents($marker.'.done', 'committed', LOCK_EX);
        exit(0);
    } catch (Throwable $error) {
        file_put_contents($marker.'.error', $error::class.': '.$error->getMessage(), LOCK_EX);
        fwrite(STDERR, $error::class.': '.$error->getMessage().PHP_EOL);
        exit(1);
    }
}

function main(array $argv): int
{
    if (($argv[1] ?? '') === '--waiter') {
        runLockProbeWaiter((string) ($argv[2] ?? ''));
    }

    $runId = (string) getenv('GITHUB_RUN_ID');
    $attempt = (string) getenv('GITHUB_RUN_ATTEMPT');
    $schema = (string) getenv('DB_DATABASE');
    $testUser = (string) getenv('DB_USERNAME');
    $expectedSchema = 'assab_lock_probe_'.$runId.'_'.$attempt;
    if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted'
        || getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'mysql'
        || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '3306'
        || getenv('DB_URL') !== '' || getenv('DATABASE_URL') !== '' || getenv('DB_SOCKET') !== ''
        || ! preg_match('/\A[0-9]+\z/', $runId) || ! preg_match('/\A[0-9]+\z/', $attempt)
        || $schema !== $expectedSchema || ! preg_match('/\Aassab_ci_[0-9]+_[0-9]+\z/', $testUser)) {
        throw new RuntimeException('Refusing lock-observation preflight outside its run-scoped GitHub-hosted disposable schema.');
    }

    $pdo = lockProbePdo();
    $server = $pdo->query('SELECT VERSION() AS version, @@version_comment AS version_comment')->fetch();
    if (! is_array($server)
        || ! preg_match('/\A8\.4\.[0-9]+(?:[-+].*)?\z/', (string) $server['version'])
        || stripos((string) $server['version_comment'], 'mysql') === false
        || stripos((string) $server['version_comment'], 'mariadb') !== false) {
        throw new RuntimeException('Lock-observation proof requires Oracle MySQL 8.4.');
    }

    // Exercise both granted table-level reads using the least-privilege CI account.
    $dataLockRows = (int) $pdo->query('SELECT COUNT(*) FROM performance_schema.data_locks')->fetchColumn();
    $waitRows = (int) $pdo->query('SELECT COUNT(*) FROM performance_schema.data_lock_waits')->fetchColumn();
    $ownerIdentity = lockProbeConnection($pdo);
    if ($dataLockRows < 0 || $waitRows < 0) {
        throw new RuntimeException('Performance Schema lock-table reads returned invalid counts.');
    }

    $existing = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $existing->execute(['assab_lock_observation_probe']);
    if ((int) $existing->fetchColumn() !== 0) {
        throw new RuntimeException('Probe table already exists; refusing reuse.');
    }
    $pdo->exec('CREATE TABLE assab_lock_observation_probe (id TINYINT UNSIGNED PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO assab_lock_observation_probe (id, value) VALUES (1, 0)');

    $marker = tempnam(sys_get_temp_dir(), 'assab-mysql-lock-probe-');
    if ($marker === false) {
        throw new RuntimeException('Could not allocate lock-probe marker.');
    }
    unlink($marker);

    $ownerInTransaction = false;
    $process = null;
    $childIdentity = null;
    $observed = null;
    $childExitCode = null;
    try {
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 30');
        $pdo->beginTransaction();
        $ownerInTransaction = true;
        $pdo->exec('UPDATE assab_lock_observation_probe SET value = value + 1 WHERE id = 1');

        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, '--waiter', $marker], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, getcwd(), null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the independent lock-probe connection.');
        }
        fclose($pipes[0]);

        $deadline = microtime(true) + 12;
        do {
            if (is_file($marker.'.error')) {
                throw new RuntimeException('Lock-probe waiter failed: '.file_get_contents($marker.'.error'));
            }
            if ($childIdentity === null && is_file($marker)) {
                $decoded = json_decode((string) file_get_contents($marker), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($decoded)) {
                    throw new RuntimeException('Lock-probe waiter did not report its connection identity.');
                }
                $childIdentity = [
                    'connection_id' => (int) $decoded['connection_id'],
                    'thread_id' => (int) $decoded['thread_id'],
                ];
                if ($childIdentity['connection_id'] === $ownerIdentity['connection_id']
                    || $childIdentity['thread_id'] === $ownerIdentity['thread_id']) {
                    throw new RuntimeException('Lock-probe owner and waiter are not independent connections.');
                }
            }
            if ($childIdentity !== null) {
                $observed = ClosureMySqlLockWaitObserver::findWait(
                    $pdo,
                    $childIdentity['connection_id'],
                    $ownerIdentity['connection_id'],
                    $schema,
                    'assab_lock_observation_probe',
                );
                if ($observed !== null) {
                    break;
                }
            }
            usleep(50000);
        } while (microtime(true) < $deadline);

        if ($observed === null || $childIdentity === null) {
            throw new RuntimeException('No real Performance Schema lock wait was observed for the probe table.');
        }
        if ($observed['table_schema'] !== $schema || $observed['table_name'] !== 'assab_lock_observation_probe'
            || (int) $observed['requesting_connection_id'] !== $childIdentity['connection_id']
            || (int) $observed['blocking_connection_id'] !== $ownerIdentity['connection_id']) {
            throw new RuntimeException('Observed lock wait did not match the disposable schema/table/requester/blocker.');
        }

        $pdo->commit();
        $ownerInTransaction = false;
        $childDeadline = microtime(true) + 10;
        while (microtime(true) < $childDeadline && is_resource($process)) {
            $status = proc_get_status($process);
            if (! $status['running']) {
                $childExitCode = (int) $status['exitcode'];
                break;
            }
            usleep(50000);
        }
        if (! is_file($marker.'.done')) {
            throw new RuntimeException('Lock-probe waiter did not complete after the blocker released the row.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeCode = proc_close($process);
        $process = null;
        $exitCode = $childExitCode ?? $closeCode;
        if ($exitCode !== 0) {
            throw new RuntimeException('Lock-probe waiter exit code '.$exitCode.'; '.$stdout.' '.$stderr);
        }

        echo 'LOCK_OBSERVATION_PREFLIGHT=PASS '.json_encode([
            'mysql_version' => $server['version'],
            'schema' => $schema,
            'table' => $observed['table_name'],
            'requesting_connection_id' => $observed['requesting_connection_id'],
            'requesting_thread_id' => $observed['requesting_thread_id'],
            'blocking_connection_id' => $observed['blocking_connection_id'],
            'blocking_thread_id' => $observed['blocking_thread_id'],
            'data_locks_rows_before_probe' => $dataLockRows,
            'data_lock_waits_rows_before_probe' => $waitRows,
        ], JSON_THROW_ON_ERROR).PHP_EOL;
    } finally {
        if ($ownerInTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_resource($process)) {
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
        }
        $pdo->exec('DROP TABLE IF EXISTS assab_lock_observation_probe');
        foreach ([$marker, $marker.'.done', $marker.'.error'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    return 0;
}

try {
    exit(main($argv));
} catch (Throwable $error) {
    fwrite(STDERR, 'LOCK_OBSERVATION_PREFLIGHT=FAIL '.$error::class.': '.$error->getMessage().PHP_EOL);
    exit(1);
}
