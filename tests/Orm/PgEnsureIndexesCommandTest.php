<?php

namespace Tests\Pinecore\Pinecore\Orm;

use League\CLImate\CLImate;
use League\CLImate\Util\Writer\Buffer;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Config;
use Pinecore\Pinecore\Console\ConsoleInput;
use Pinecore\Pinecore\Console\ConsoleOutput;
use Pinecore\Pinecore\Orm\Console\PgEnsureIndexesCommand;
use Pinecore\Pinecore\Orm\Sql\PostgresDialect;
use Tests\Pinecore\Pinecore\Fixtures\Orm\BadIndexMethodEntity;
use Tests\Pinecore\Pinecore\Fixtures\Orm\Document;
use Tests\Pinecore\Pinecore\Fixtures\Orm\GeoPlace;
use Tests\Pinecore\Pinecore\Fixtures\Orm\IndexedEntity;
use Tests\Pinecore\Pinecore\Fixtures\Orm\LabeledRow;

/**
 * DDL generation only — no server is touched. The Mongo entity in the config is
 * there on purpose: pg:ensure-indexes must ignore anything that is not a SqlEntity.
 */
final class PgEnsureIndexesCommandTest extends TestCase
{
    #[Test]
    public function geometryColumnGetsAGistIndex(): void
    {
        self::assertContains(
            'CREATE INDEX IF NOT EXISTS "idx_places_geom_gist" ON "places" USING gist ("geom")',
            $this->statementsFor(GeoPlace::class),
        );
    }

    #[Test]
    public function indexMethodIsHonouredOnSingleColumnIndexes(): void
    {
        self::assertContains(
            'CREATE INDEX IF NOT EXISTS "idx_places_bbox" ON "places" USING gist ("bbox")',
            $this->statementsFor(GeoPlace::class),
        );
    }

    #[Test]
    public function pgArrayColumnGetsItsGinIndex(): void
    {
        self::assertSame(
            ['CREATE INDEX IF NOT EXISTS "idx_labeled_rows_labels" ON "labeled_rows" USING gin ("labels")'],
            $this->statementsFor(LabeledRow::class),
        );
    }

    #[Test]
    public function compositeUniqueIndexIsGenerated(): void
    {
        self::assertContains(
            'CREATE UNIQUE INDEX IF NOT EXISTS "idx_places_source_external_id" '
            . 'ON "places" ("source", "external_id")',
            $this->statementsFor(GeoPlace::class),
        );
    }

    #[Test]
    public function compositeIndexHonoursExplicitName(): void
    {
        self::assertContains(
            'CREATE INDEX IF NOT EXISTS "idx_ctx" ON "places" ("context_hash", "place_id")',
            $this->statementsFor(GeoPlace::class),
        );
    }

    #[Test]
    public function everyStatementIsIdempotent(): void
    {
        foreach ($this->statementsFor(GeoPlace::class) as $sql) {
            self::assertStringContainsString('IF NOT EXISTS', $sql);
        }
    }

    #[Test]
    public function rerunProducesTheSameStatements(): void
    {
        self::assertSame($this->statementsFor(GeoPlace::class), $this->statementsFor(GeoPlace::class));
    }

    #[Test]
    public function existingBehaviourIsUnchangedForPlainEntities(): void
    {
        self::assertSame(
            ['CREATE INDEX IF NOT EXISTS "idx_documents_embedding_hnsw" '
                . 'ON "documents" USING hnsw ("embedding" vector_cosine_ops)'],
            $this->statementsFor(Document::class),
        );
    }

    #[Test]
    public function mongoEntitiesAreIgnored(): void
    {
        self::assertSame([], $this->statementsFor(IndexedEntity::class));
    }

    #[Test]
    public function dryRunPrintsTheDdlWithoutTouchingTheDatabase(): void
    {
        $pdo    = new SpyPdo();
        $buffer = new Buffer();

        $this->command($pdo)(self::input(true), self::consoleOutput($buffer));

        self::assertSame([], $pdo->executed, 'dry-run must not issue DDL');
        self::assertStringContainsString(
            'CREATE UNIQUE INDEX IF NOT EXISTS "idx_places_source_external_id"',
            $buffer->get(),
        );
        self::assertStringContainsString('[dry-run]', $buffer->get());
    }

    #[Test]
    public function unknownIndexMethodIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown index method/');

        $this->statementsFor(BadIndexMethodEntity::class);
    }

    /** @return list<string> */
    private function statementsFor(string $entity): array
    {
        $pdo = new SpyPdo();
        $this->command($pdo, [$entity])(self::input(false), self::consoleOutput(new Buffer()));

        return $pdo->executed;
    }

    /** @param list<class-string> $entities */
    private function command(SpyPdo $pdo, array $entities = [GeoPlace::class]): PgEnsureIndexesCommand
    {
        return new PgEnsureIndexesCommand(
            new Config(['orm' => ['entities' => $entities]]),
            $pdo,
            new PostgresDialect(),
        );
    }

    private static function input(bool $dryRun): ConsoleInput
    {
        return new ConsoleInput(
            command: 'pg:ensure-indexes',
            pathParams: [],
            arguments: [],
            options: ['dry-run' => $dryRun],
        );
    }

    private static function consoleOutput(Buffer $buffer): ConsoleOutput
    {
        $climate = new CLImate();
        $climate->output->add('out', $buffer);
        $climate->output->add('error', new Buffer());
        $climate->output->defaultTo('out');

        return new ConsoleOutput($climate);
    }
}

/** PDO double recording DDL instead of running it. */
final class SpyPdo extends PDO
{
    /** @var list<string> */
    public array $executed = [];

    public function __construct()
    {
        // Intentionally skip parent constructor — no real connection.
    }

    public function exec(string $statement): int|false
    {
        $this->executed[] = $statement;
        return 0;
    }
}
