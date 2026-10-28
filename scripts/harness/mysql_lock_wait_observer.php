<?php

declare(strict_types=1);

/** Shared Oracle MySQL lock-wait observer for Track B tests and preflight. */
final class ClosureMySqlLockWaitObserver
{
    /**
     * Return a verified InnoDB row-lock wait for the supplied two connections.
     * An empty result means no such wait is visible yet; permission/query errors
     * deliberately propagate so callers cannot turn missing monitoring into PASS.
     *
     * @return array<string, int|string>|null
     */
    public static function findWait(
        PDO $observer,
        int $requestingConnectionId,
        int $blockingConnectionId,
        string $expectedSchema,
        string $expectedTable,
    ): ?array {
        $statement = $observer->prepare(<<<'SQL'
SELECT waiting_lock.OBJECT_SCHEMA AS table_schema,
       waiting_lock.OBJECT_NAME AS table_name,
       waits.ENGINE AS engine,
       waiting_lock.LOCK_STATUS AS lock_status,
       waits.REQUESTING_THREAD_ID AS requesting_thread_id,
       waits.BLOCKING_THREAD_ID AS blocking_thread_id,
       PS_THREAD_ID(?) AS requesting_connection_thread_id,
       PS_THREAD_ID(?) AS blocking_connection_thread_id
FROM performance_schema.data_lock_waits AS waits
JOIN performance_schema.data_locks AS waiting_lock
  ON waiting_lock.ENGINE = waits.ENGINE
 AND waiting_lock.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID
WHERE waits.REQUESTING_THREAD_ID = PS_THREAD_ID(?)
  AND waits.BLOCKING_THREAD_ID = PS_THREAD_ID(?)
  AND waiting_lock.OBJECT_SCHEMA = ?
  AND waiting_lock.OBJECT_NAME = ?
  AND waiting_lock.LOCK_STATUS = 'WAITING'
LIMIT 1
SQL);
        $statement->execute([
            $requestingConnectionId,
            $blockingConnectionId,
            $requestingConnectionId,
            $blockingConnectionId,
            $expectedSchema,
            $expectedTable,
        ]);
        $wait = $statement->fetch(PDO::FETCH_ASSOC);

        if ($wait === false) {
            return null;
        }

        if (strtoupper((string) $wait['engine']) !== 'INNODB') {
            throw new RuntimeException('Observed lock wait is not an InnoDB lock.');
        }

        $wait['requesting_connection_id'] = $requestingConnectionId;
        $wait['blocking_connection_id'] = $blockingConnectionId;

        if ((int) $wait['requesting_thread_id'] !== (int) $wait['requesting_connection_thread_id']) {
            throw new RuntimeException('Performance Schema requester thread does not map to the expected connection.');
        }
        if ((int) $wait['blocking_thread_id'] !== (int) $wait['blocking_connection_thread_id']) {
            throw new RuntimeException('Performance Schema blocker thread does not map to the expected connection.');
        }

        return $wait;
    }
}
