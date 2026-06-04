<?php

namespace Pinecore\Pinecore\Orm\Sql;

use PDO;
use Pinecore\Pinecore\WorkerLifecycle;

/**
 * Reusable, connection-loss-aware database ping for readiness probes.
 *
 * A readiness controller that hits the PDO directly bypasses the recycle guard in
 * AbstractSqlRepository::execute(): when worker.max_requests=0 the worker keeps a
 * dead PDO across a Postgres restart, so /ready returns 503 forever until a manual
 * restart. Routing the probe through this service closes that gap — on a lost
 * connection it flags the worker for recycling via the same WorkerLifecycle the
 * ProcessLoop reads, so the runtime spawns a fresh worker with a live connection
 * and /ready self-heals once the database is back.
 *
 * WorkerLifecycle is the same shared singleton repositories use; pass null outside
 * a worker (console, tests).
 */
final class SqlHealthCheck
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SqlDialect $dialect,
        private readonly ?WorkerLifecycle $lifecycle = null,
    ) {}

    /**
     * Returns true if the database answers a trivial query. On any failure returns
     * false (the probe should report "not ready"); if the failure is specifically a
     * lost connection, the worker is additionally flagged for graceful recycling.
     */
    public function isHealthy(): bool
    {
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (\PDOException $e) {
            if ($this->dialect->isConnectionLost($e)) {
                $this->lifecycle?->requestShutdown();
            }
            return false;
        }
    }
}
