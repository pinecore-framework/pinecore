<?php

namespace Tests\Pinecore\Pinecore\Orm;

use League\CLImate\CLImate;
use League\CLImate\Util\Writer\Buffer;
use MongoDB\Collection;
use MongoDB\Database;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Config;
use Pinecore\Pinecore\Console\ConsoleInput;
use Pinecore\Pinecore\Console\ConsoleOutput;
use Pinecore\Pinecore\Orm\Console\EnsureIndexesCommand;
use Tests\Pinecore\Pinecore\Fixtures\Orm\EmbeddedAddress;
use Tests\Pinecore\Pinecore\Fixtures\Orm\IndexedEntity;
use Tests\Pinecore\Pinecore\Fixtures\Orm\SimpleUser;

final class EnsureIndexesCommandTest extends TestCase
{
    #[Test]
    public function ensuresEachDeclaredIndex(): void
    {
        $db = new SpyDatabase();

        $command = new EnsureIndexesCommand(
            new Config(['orm' => ['entities' => [IndexedEntity::class]]]),
            $db,
        );

        $command(self::input(['dry-run' => false]), self::silentOutput());

        $coll = $db->collections['indexed_entities'] ?? null;
        self::assertNotNull($coll);

        self::assertSame(
            [
                ['keys' => ['email' => 1],      'options' => ['unique' => true, 'name' => 'uniq_email']],
                ['keys' => ['created_at' => -1], 'options' => ['expireAfterSeconds' => 3600]],
                ['keys' => ['referralCode' => 1], 'options' => ['sparse' => true]],
            ],
            $coll->calls,
        );
    }

    #[Test]
    public function entityWithoutCollectionIsSkipped(): void
    {
        $db = new SpyDatabase();

        $command = new EnsureIndexesCommand(
            new Config(['orm' => ['entities' => [EmbeddedAddress::class, SimpleUser::class]]]),
            $db,
        );

        $command(self::input(['dry-run' => false]), self::silentOutput());

        // EmbeddedAddress has no #[Collection] → skipped, no selectCollection.
        // SimpleUser has #[Collection] but no #[Index] → no createIndex calls.
        self::assertArrayNotHasKey('embedded_address', $db->collections);
        $simple = $db->collections['simple_users'] ?? null;
        if ($simple !== null) {
            self::assertSame([], $simple->calls);
        }
    }

    #[Test]
    public function dryRunDoesNotCallCreateIndex(): void
    {
        $db = new SpyDatabase();

        $command = new EnsureIndexesCommand(
            new Config(['orm' => ['entities' => [IndexedEntity::class]]]),
            $db,
        );

        $command(self::input(['dry-run' => true]), self::silentOutput());

        self::assertSame([], $db->collections);
    }

    #[Test]
    public function emptyEntitiesListExitsWithCodeOne(): void
    {
        $db = new SpyDatabase();

        $command = new EnsureIndexesCommand(
            new Config(['orm' => ['entities' => []]]),
            $db,
        );

        $output = self::silentOutput();
        $command(self::input(['dry-run' => false]), $output);

        self::assertSame(1, $output->getExitCode());
        self::assertSame([], $db->collections);
    }

    /** @param array<string, mixed> $options */
    private static function input(array $options): ConsoleInput
    {
        return new ConsoleInput(
            command: 'mongo:ensure-indexes',
            pathParams: [],
            arguments: [],
            options: $options,
        );
    }

    private static function silentOutput(): ConsoleOutput
    {
        $climate = new CLImate();
        $climate->output->add('out',   new Buffer());
        $climate->output->add('error', new Buffer());
        $climate->output->defaultTo('out');
        return new ConsoleOutput($climate);
    }
}

/**
 * Records calls to createIndex without touching MongoDB.
 * Constructor is overridden to skip parent's Manager dependency.
 */
final class SpyCollection extends Collection
{
    /** @var list<array{keys: array<string, int>, options: array<string, mixed>}> */
    public array $calls = [];

    public function __construct() {}

    public function createIndex(array|object $key, array $options = []): string
    {
        $this->calls[] = ['keys' => (array) $key, 'options' => $options];
        return 'fake_index_' . count($this->calls);
    }
}

final class SpyDatabase extends Database
{
    /** @var array<string, SpyCollection> */
    public array $collections = [];

    public function __construct() {}

    public function selectCollection(string $collectionName, array $options = []): Collection
    {
        return $this->collections[$collectionName] ??= new SpyCollection();
    }
}
