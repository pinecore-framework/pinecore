<?php

namespace Tests\Pinecore\Pinecore\Orm;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Orm\Sql\PostgresDialect;
use Pinecore\Pinecore\Orm\Sql\SqlHealthCheck;
use Pinecore\Pinecore\WorkerLifecycle;

final class SqlHealthCheckTest extends TestCase
{
    #[Test]
    public function healthyConnectionReportsReady(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite not available');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $lifecycle = new WorkerLifecycle();

        $health = new SqlHealthCheck($pdo, new PostgresDialect(), $lifecycle);

        self::assertTrue($health->isHealthy());
        self::assertFalse($lifecycle->shouldShutdown());
    }

    #[Test]
    public function lostConnectionReportsNotReadyAndFlagsRecycle(): void
    {
        $lifecycle = new WorkerLifecycle();
        $health    = new SqlHealthCheck(
            new ThrowingQueryPdo('server closed the connection unexpectedly'),
            new PostgresDialect(),
            $lifecycle,
        );

        self::assertFalse($health->isHealthy());
        self::assertTrue($lifecycle->shouldShutdown(), 'lost connection must flag the worker for recycle');
    }

    #[Test]
    public function otherDbErrorReportsNotReadyWithoutRecycle(): void
    {
        $lifecycle = new WorkerLifecycle();
        $health    = new SqlHealthCheck(
            new ThrowingQueryPdo('permission denied for relation x'),
            new PostgresDialect(),
            $lifecycle,
        );

        self::assertFalse($health->isHealthy());
        self::assertFalse($lifecycle->shouldShutdown(), 'a non-connection error must not recycle');
    }
}

/** PDO double whose query() always throws, simulating a driver failure on ping. */
final class ThrowingQueryPdo extends PDO
{
    public function __construct(private readonly string $message)
    {
        // Intentionally skip parent constructor — no real connection.
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        throw new \PDOException($this->message);
    }
}
