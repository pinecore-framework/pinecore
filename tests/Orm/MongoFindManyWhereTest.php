<?php

namespace Tests\Pinecore\Pinecore\Orm;

use ArrayIterator;
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

/**
 * Mongo findManyWhere(): the filter goes to Collection::find() verbatim, ordering
 * and the window become the driver's sort/limit/skip options, and the cursor is
 * materialised into entities. Asserted on a recording collection.
 */
final class MongoFindManyWhereTest extends TestCase
{
    #[Test]
    public function passesFilterAndMapsOrderingToDriverOptions(): void
    {
        $collection = new RecordingCollection([
            ['id' => 'u2', 'email' => 'b@x', 'display_name' => 'B'],
            ['id' => 'u1', 'email' => 'a@x'],
        ]);

        $users = $this->repo($collection)->listWhere(
            ['email' => ['$regex' => '@x$']],
            ['displayName' => 'desc', 'id' => 'ASC'],
            10,
            5,
        );

        self::assertSame([['email' => ['$regex' => '@x$']]], array_column($collection->calls, 'filter'));
        self::assertSame(
            ['sort' => ['display_name' => -1, 'id' => 1], 'limit' => 10, 'skip' => 5],
            $collection->calls[0]['options'],
        );
        self::assertContainsOnlyInstancesOf(SimpleUser::class, $users);
        self::assertSame(['u2', 'u1'], array_map(static fn(SimpleUser $u): string => $u->id, $users));
        self::assertSame('B', $users[0]->displayName);
        self::assertNull($users[1]->displayName);
    }

    #[Test]
    public function omitsOptionsThatWereNotGiven(): void
    {
        $collection = new RecordingCollection([]);

        self::assertSame([], $this->repo($collection)->listWhere([]));
        self::assertSame([], $collection->calls[0]['filter']);
        self::assertSame([], $collection->calls[0]['options']);
    }

    #[Test]
    public function documentKeyAndPropertyNameSortAlike(): void
    {
        $collection = new RecordingCollection([]);
        $repo       = $this->repo($collection);

        $repo->listWhere([], ['display_name' => 'asc']);
        $repo->listWhere([], ['displayName' => 'asc']);

        self::assertSame($collection->calls[0]['options'], $collection->calls[1]['options']);
        self::assertSame(['sort' => ['display_name' => 1]], $collection->calls[0]['options']);
    }

    #[Test]
    public function zeroLimitShortCircuitsInsteadOfMeaningUnlimited(): void
    {
        $collection = new RecordingCollection([['id' => 'u1', 'email' => 'a@x']]);

        self::assertSame([], $this->repo($collection)->listWhere([], [], 0));
        self::assertSame([], $collection->calls, 'driver treats limit 0 as no limit; must not be called');
    }

    #[Test]
    public function unknownSortKeyIsRejected(): void
    {
        $collection = new RecordingCollection([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown order-by key');
        $this->repo($collection)->listWhere([], ['nope' => 'asc']);
    }

    #[Test]
    public function badDirectionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("must be 'asc' or 'desc'");
        $this->repo(new RecordingCollection([]))->listWhere([], ['id' => 'up']);
    }

    #[Test]
    public function negativeWindowIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repo(new RecordingCollection([]))->listWhere([], [], null, -3);
    }

    #[Test]
    public function lostConnectionFlagsWorkerForRecycle(): void
    {
        $lifecycle = new WorkerLifecycle();
        $repo      = new SimpleUserRepository(
            new CollectionDatabase(new RecordingCollection([], throwOnFind: true)),
            new MongoHydrator(),
            new IdGenerator(),
            $lifecycle,
        );

        $this->expectException(ConnectionException::class);
        try {
            $repo->listWhere([]);
        } finally {
            self::assertTrue($lifecycle->shouldShutdown());
        }
    }

    private function repo(RecordingCollection $collection): SimpleUserRepository
    {
        return new SimpleUserRepository(new CollectionDatabase($collection), new MongoHydrator(), new IdGenerator(), new WorkerLifecycle());
    }
}

final class RecordingCollection extends Collection
{
    /** @var list<array{filter: array, options: array}> */
    public array $calls = [];

    /** @param list<array<string, mixed>> $docs */
    public function __construct(private readonly array $docs, private readonly bool $throwOnFind = false)
    {
        // Intentionally skip parent constructor — no real connection.
    }

    public function find(array|object $filter = [], array $options = [])
    {
        if ($this->throwOnFind) {
            throw new ConnectionException('connection lost');
        }
        $this->calls[] = ['filter' => (array) $filter, 'options' => $options];
        return new ArrayIterator($this->docs);
    }
}

final class CollectionDatabase extends Database
{
    public function __construct(private readonly Collection $collection)
    {
        // Intentionally skip parent constructor.
    }

    public function selectCollection(string $collectionName, array $options = []): Collection
    {
        return $this->collection;
    }
}
