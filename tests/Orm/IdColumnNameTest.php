<?php

namespace Tests\Pinecore\Pinecore\Orm;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Orm\EntityMap;
use Pinecore\Pinecore\Orm\IdGenerator;
use Pinecore\Pinecore\Orm\MongoHydrator;
use Pinecore\Pinecore\Orm\Sql\PostgresDialect;
use Pinecore\Pinecore\Orm\SqlHydrator;
use Tests\Pinecore\Pinecore\Fixtures\Orm\Document;
use Tests\Pinecore\Pinecore\Fixtures\Orm\LinkRow;
use Tests\Pinecore\Pinecore\Fixtures\Orm\LinkRowRepository;
use Tests\Pinecore\Pinecore\Fixtures\Orm\NamedIdUser;
use Tests\Pinecore\Pinecore\Fixtures\Orm\SimpleUser;

/**
 * #[Id(name: 'object_id')] — the primary key column may be named differently
 * from the property, symmetric with #[Field(name:)]. FieldMetadata::$field is
 * the column everywhere; the property name is only used to read/construct the
 * entity.
 */
final class IdColumnNameTest extends TestCase
{
    #[Test]
    public function metadataSeparatesColumnFromProperty(): void
    {
        $id = EntityMap::of(LinkRow::class)->idField();

        self::assertTrue($id->isId);
        self::assertSame('object_id', $id->field);
        self::assertSame('objectId', $id->property->getName());
    }

    #[Test]
    public function bareIdStillMapsToThePropertyName(): void
    {
        self::assertSame('id', EntityMap::of(Document::class)->idField()->field);
        self::assertSame('id', EntityMap::of(SimpleUser::class)->idField()->field);
    }

    #[Test]
    public function sqlHydratorRoundTripsThroughTheColumnName(): void
    {
        $hydrator = new SqlHydrator(new PostgresDialect());

        $row = $hydrator->dehydrate(new LinkRow('o1', 'c1', 'Title'));
        self::assertSame(['object_id' => 'o1', 'class_id' => 'c1', 'title' => 'Title'], $row);
        self::assertArrayNotHasKey('objectId', $row);

        $entity = $hydrator->hydrate(LinkRow::class, $row);
        self::assertInstanceOf(LinkRow::class, $entity);
        self::assertSame('o1', $entity->objectId);
        self::assertSame('c1', $entity->classId);
    }

    #[Test]
    public function hydrationRequiresTheColumnNotTheProperty(): void
    {
        $hydrator = new SqlHydrator(new PostgresDialect());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Missing required column 'object_id'");
        $hydrator->hydrate(LinkRow::class, ['objectId' => 'o1', 'class_id' => 'c1']);
    }

    #[Test]
    public function persistUpsertsOnTheIdColumn(): void
    {
        $pdo = new IdRecordingPdo();
        $this->repo($pdo)->save(new LinkRow('o1', 'c1'));

        self::assertSame(
            'INSERT INTO "links" ("object_id", "class_id", "title") VALUES (:object_id, :class_id, :title) '
            . 'ON CONFLICT ("object_id") DO UPDATE SET "class_id" = EXCLUDED."class_id", "title" = EXCLUDED."title"',
            $pdo->prepared[0],
        );
        self::assertSame(['object_id' => 'o1', 'class_id' => 'c1', 'title' => null], $pdo->params[0]);
    }

    #[Test]
    public function persistAllUpsertsOnTheIdColumn(): void
    {
        $pdo = new IdRecordingPdo();
        $this->repo($pdo)->saveAll([new LinkRow('o1', 'c1'), new LinkRow('o2', 'c1')]);

        self::assertStringContainsString('ON CONFLICT ("object_id") DO UPDATE SET', $pdo->prepared[0]);
        self::assertStringStartsWith('INSERT INTO "links" ("object_id", "class_id", "title") VALUES', $pdo->prepared[0]);
    }

    #[Test]
    public function findByIdAndDeleteFilterOnTheIdColumn(): void
    {
        $pdo  = new IdRecordingPdo();
        $repo = $this->repo($pdo);

        $repo->find('o1');
        $repo->remove('o1');

        self::assertSame(
            'SELECT "object_id", "class_id", "title" FROM "links" WHERE "object_id" = :id LIMIT 1',
            $pdo->prepared[0],
        );
        self::assertSame('DELETE FROM "links" WHERE "object_id" = :id', $pdo->prepared[1]);
    }

    #[Test]
    public function crudRoundTripsOnSqlite(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite not available');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE links (object_id TEXT PRIMARY KEY, class_id TEXT NOT NULL, title TEXT)');
        $repo = $this->repo($pdo);

        $repo->save(new LinkRow('o1', 'c1', 'first'));
        $repo->save(new LinkRow('o1', 'c2', 'second'));
        $generated = $repo->save(new LinkRow('', 'c3'));

        self::assertNotSame('', $generated->objectId);
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM links')->fetchColumn());

        $found = $repo->find('o1');
        self::assertSame('o1', $found?->objectId);
        self::assertSame('c2', $found?->classId);
        self::assertSame('second', $found?->title);

        $repo->remove('o1');
        self::assertNull($repo->find('o1'));
        self::assertNotNull($repo->find($generated->objectId));
    }

    #[Test]
    public function mongoHydratorUsesTheCustomDocumentKey(): void
    {
        $hydrator = new MongoHydrator();

        $doc = $hydrator->dehydrate(new NamedIdUser('u1', 'a@b.c'));
        self::assertSame(['user_id' => 'u1', 'email' => 'a@b.c'], $doc);

        $entity = $hydrator->hydrate(NamedIdUser::class, $doc);
        self::assertSame('u1', $entity->userId);
        self::assertSame('user_id', EntityMap::of(NamedIdUser::class)->idField()->field);
    }

    private function repo(PDO $pdo): LinkRowRepository
    {
        $dialect = new PostgresDialect();
        return new LinkRowRepository($pdo, $dialect, new SqlHydrator($dialect), new IdGenerator());
    }
}

/** Records prepared SQL and bound parameters; never touches a database. */
final class IdRecordingPdo extends PDO
{
    /** @var list<string> */
    public array $prepared = [];
    /** @var list<array<string, mixed>|null> */
    public array $params = [];

    public function __construct()
    {
        // Intentionally skip parent constructor — no real connection.
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepared[] = $query;
        return new IdRecordingStatement($this);
    }
}

final class IdRecordingStatement extends PDOStatement
{
    public function __construct(private readonly IdRecordingPdo $owner) {}

    public function execute(?array $params = null): bool
    {
        $this->owner->params[] = $params;
        return true;
    }

    public function rowCount(): int
    {
        return 0;
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0,
    ): mixed {
        return false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [];
    }
}
