<?php

namespace Tests\Pinecore\Pinecore\Orm;

use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Exception\ConnectionException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Orm\IdGenerator;
use Pinecore\Pinecore\Orm\MongoHydrator;
use Pinecore\Pinecore\WorkerLifecycle;
use Tests\Pinecore\Pinecore\Fixtures\Orm\SimpleUser;
use Tests\Pinecore\Pinecore\Fixtures\Orm\SimpleUserRepository;

final class AbstractMongoRepositoryTest extends TestCase
{
    #[Test]
    public function lostConnectionFlagsWorkerForRecycle(): void
    {
        $lifecycle = new WorkerLifecycle();
        $repo      = new SimpleUserRepository(
            new ConnectionLostDatabase(),
            new MongoHydrator(),
            new IdGenerator(),
            $lifecycle,
        );

        $this->expectException(ConnectionException::class);
        try {
            $repo->save(new SimpleUser('u1', 'a@b.c'));
        } finally {
            self::assertTrue($lifecycle->shouldShutdown(), 'worker should be flagged for recycle');
        }
    }
}

final class ConnectionLostCollection extends Collection
{
    public function __construct() {}

    public function replaceOne(array|object $filter, array|object $replacement, array $options = [])
    {
        throw new ConnectionException('connection lost');
    }

    public function findOne(array|object $filter = [], array $options = [])
    {
        throw new ConnectionException('connection lost');
    }
}

final class ConnectionLostDatabase extends Database
{
    public function __construct() {}

    public function selectCollection(string $collectionName, array $options = []): Collection
    {
        return new ConnectionLostCollection();
    }
}
