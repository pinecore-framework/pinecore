<?php

namespace Tests\Pinecore\Pinecore\Orm;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Orm\IdGenerator;
use Pinecore\Pinecore\Orm\Sql\PostgresDialect;
use Pinecore\Pinecore\Orm\SqlHydrator;
use Pinecore\Pinecore\WorkerLifecycle;
use Tests\Pinecore\Pinecore\Fixtures\Orm\Document;
use Tests\Pinecore\Pinecore\Fixtures\Orm\DocumentRepository;

/**
 * transactional() wraps a unit of work while keeping the connection-loss guard.
 * Nested calls join the open transaction rather than opening a second one, which
 * is what makes several repositories on the shared PDO singleton composable.
 */
final class SqlTransactionTest extends TestCase
{
    private DocumentRepository $repo;
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite not available');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE documents (
                id        TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                title     TEXT NOT NULL,
                embedding TEXT,
                status    TEXT,
                metadata  TEXT
            )'
        );

        $dialect    = new PostgresDialect();
        $this->repo = new DocumentRepository($this->pdo, $dialect, new SqlHydrator($dialect), new IdGenerator(), new WorkerLifecycle());
    }

    #[Test]
    public function commitsOnSuccessAndReturnsTheCallableResult(): void
    {
        $result = $this->repo->atomically(function (): string {
            $this->repo->save($this->doc('d1'));
            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertNotNull($this->repo->find('d1'));
        self::assertFalse($this->pdo->inTransaction(), 'transaction must not stay open');
    }

    #[Test]
    public function exceptionInsideRollsBackAndRethrows(): void
    {
        $this->repo->save($this->doc('kept'));

        try {
            $this->repo->atomically(function (): void {
                $this->repo->save($this->doc('rolled-back'));
                throw new \RuntimeException('boom');
            });
            self::fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertNull($this->repo->find('rolled-back'));
        self::assertNotNull($this->repo->find('kept'));
        self::assertFalse($this->pdo->inTransaction());
    }

    #[Test]
    public function nestedCallJoinsTheOpenTransaction(): void
    {
        // A second beginTransaction() on the shared PDO would throw here.
        $this->repo->savePairAtomically($this->doc('d1'), $this->doc('d2'));

        self::assertNotNull($this->repo->find('d1'));
        self::assertNotNull($this->repo->find('d2'));
        self::assertFalse($this->pdo->inTransaction());
    }

    #[Test]
    public function failureInsideANestedCallRollsBackTheWholeUnit(): void
    {
        try {
            $this->repo->atomically(function (): void {
                $this->repo->save($this->doc('outer'));
                $this->repo->atomically(function (): void {
                    $this->repo->save($this->doc('inner'));
                    throw new \RuntimeException('inner failure');
                });
            });
            self::fail('expected RuntimeException');
        } catch (\RuntimeException) {
            // expected — rethrown through both levels
        }

        self::assertNull($this->repo->find('outer'), 'the outer write must roll back too');
        self::assertNull($this->repo->find('inner'));
        self::assertFalse($this->pdo->inTransaction());
    }

    #[Test]
    public function nestedCallReturnsItsValueToTheCaller(): void
    {
        $value = $this->repo->atomically(fn(): int => $this->repo->atomically(fn(): int => 42));

        self::assertSame(42, $value);
    }

    #[Test]
    public function lostConnectionInsideATransactionFlagsWorkerForRecycle(): void
    {
        $lifecycle = new WorkerLifecycle();
        $repo      = $this->repoOnPdo(new TxPdo('server closed the connection unexpectedly'), $lifecycle);

        $this->expectException(\PDOException::class);
        try {
            $repo->atomically(fn() => $repo->save($this->doc('d1')));
        } finally {
            self::assertTrue($lifecycle->shouldShutdown(), 'transactional() must keep the connection-loss guard');
        }
    }

    #[Test]
    public function queryErrorInsideATransactionDoesNotRecycleWorker(): void
    {
        $lifecycle = new WorkerLifecycle();
        $repo      = $this->repoOnPdo(new TxPdo('duplicate key value violates unique constraint'), $lifecycle);

        try {
            $repo->atomically(fn() => $repo->save($this->doc('d1')));
            self::fail('expected PDOException');
        } catch (\PDOException) {
            // expected — rethrown
        }

        self::assertFalse($lifecycle->shouldShutdown());
    }

    /** A rollback that fails on a dead connection must not mask the real failure. */
    #[Test]
    public function failingRollbackDoesNotMaskTheOriginalException(): void
    {
        $pdo  = new TxPdo('server closed the connection unexpectedly', rollBackFails: true);
        $repo = $this->repoOnPdo($pdo, new WorkerLifecycle());

        try {
            $repo->atomically(fn() => $repo->save($this->doc('d1')));
            self::fail('expected PDOException');
        } catch (\PDOException $e) {
            self::assertStringContainsString('server closed the connection', $e->getMessage());
        }
    }

    private function doc(string $id): Document
    {
        return new Document($id, 't1', $id, [1.0, 2.0, 3.0]);
    }

    private function repoOnPdo(PDO $pdo, WorkerLifecycle $lifecycle): DocumentRepository
    {
        $dialect = new PostgresDialect();
        return new DocumentRepository($pdo, $dialect, new SqlHydrator($dialect), new IdGenerator(), $lifecycle);
    }
}

/**
 * PDO double that tracks transaction state but fails every statement, so the
 * guard path can be exercised without a server.
 */
final class TxPdo extends PDO
{
    private bool $inTx = false;

    public function __construct(
        private readonly string $message,
        private readonly bool $rollBackFails = false,
    ) {
        // Intentionally skip parent constructor — no real connection.
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        throw new \PDOException($this->message);
    }

    public function inTransaction(): bool
    {
        return $this->inTx;
    }

    public function beginTransaction(): bool
    {
        return $this->inTx = true;
    }

    public function commit(): bool
    {
        $this->inTx = false;
        return true;
    }

    public function rollBack(): bool
    {
        if ($this->rollBackFails) {
            throw new \PDOException('rollback failed: no connection to the server');
        }
        $this->inTx = false;
        return true;
    }
}
