<?php

namespace Tests\Pinecore\Pinecore\Orm;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Orm\IdGenerator;
use Pinecore\Pinecore\Orm\Sql\PostgresDialect;
use Pinecore\Pinecore\Orm\SqlHydrator;
use Pinecore\Pinecore\WorkerLifecycle;
use Tests\Pinecore\Pinecore\Fixtures\Orm\LabeledRow;
use Tests\Pinecore\Pinecore\Fixtures\Orm\LabeledRowRepository;

/**
 * text[] support (#[PgArray]): the dialect literal on write, the pdo_pgsql
 * output-format parser on read, and the guard rails — no NULL elements, no
 * non-strings, no multidimensional arrays, no equality filters on arrays.
 */
final class PgArrayTest extends TestCase
{
    private PostgresDialect $dialect;
    private SqlHydrator $hydrator;

    protected function setUp(): void
    {
        $this->dialect  = new PostgresDialect();
        $this->hydrator = new SqlHydrator($this->dialect);
    }

    // ---- write: arrayLiteral() ---------------------------------------------

    #[Test]
    public function emptyArrayEncodesToEmptyBraces(): void
    {
        self::assertSame('{}', $this->dialect->arrayLiteral([]));
    }

    #[Test]
    public function everyElementIsAlwaysQuoted(): void
    {
        self::assertSame('{"a"}', $this->dialect->arrayLiteral(['a']));
        self::assertSame('{"a","b"}', $this->dialect->arrayLiteral(['a', 'b']));
    }

    #[Test]
    public function quotesAndBackslashesAreEscaped(): void
    {
        self::assertSame('{"say \"hi\""}', $this->dialect->arrayLiteral(['say "hi"']));
        self::assertSame('{"a\\\\b"}', $this->dialect->arrayLiteral(['a\\b']));   // a\b → "a\\b"
    }

    #[Test]
    public function nullElementIsRejectedOnWrite(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must not be null/');

        $this->dialect->arrayLiteral(['a', null]);
    }

    #[Test]
    public function nonStringElementIsRejectedOnWriteNotCoerced(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be strings, got int/');

        $this->dialect->arrayLiteral([42]);
    }

    #[Test]
    public function nestedArrayElementIsRejectedOnWrite(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be strings, got array/');

        $this->dialect->arrayLiteral([['a']]);
    }

    // ---- read: the output-format parser ------------------------------------

    #[Test]
    public function emptyBracesHydrateToEmptyArray(): void
    {
        self::assertSame([], $this->row(labels: '{}')->labels);
    }

    #[Test]
    public function unquotedElementsAreParsed(): void
    {
        // Postgres leaves plain elements unquoted: ARRAY['a','b'] prints as {a,b}.
        self::assertSame(['a', 'b'], $this->row(labels: '{a,b}')->labels);
    }

    #[Test]
    public function quotedElementsWithEscapesAreParsed(): void
    {
        self::assertSame(['say "hi"', 'a\\b'], $this->row(labels: '{"say \"hi\"","a\\\\b"}')->labels);
    }

    #[Test]
    public function delimitersInsideQuotedElementsDoNotSplit(): void
    {
        self::assertSame(['a,b', '{x}', ' spaced '], $this->row(labels: '{"a,b","{x}"," spaced "}')->labels);
    }

    #[Test]
    public function emptyStringElementSurvives(): void
    {
        self::assertSame([''], $this->row(labels: '{""}')->labels);
    }

    #[Test]
    public function quotedNullIsAStringNotAToken(): void
    {
        self::assertSame(['NULL'], $this->row(labels: '{"NULL"}')->labels);
    }

    #[Test]
    public function bareNullTokenIsRejectedOnRead(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/NULL element in text\[\] column \'labels\'/');

        $this->row(labels: '{a,NULL}');
    }

    #[Test]
    public function multidimensionalLiteralIsRejectedOnRead(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Multidimensional arrays are not supported/');

        $this->row(labels: '{{a},{b}}');
    }

    #[Test]
    public function malformedLiteralIsRejectedOnRead(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Malformed text\[\] literal in column \'labels\'/');

        $this->row(labels: 'a,b');
    }

    // ---- round-trips --------------------------------------------------------

    /** @return iterable<string, array{list<string>}> */
    public static function roundTripArrays(): iterable
    {
        yield 'empty'          => [[]];
        yield 'single'         => [['a']];
        yield 'comma'          => [['a,b', 'c']];
        yield 'quotes'         => [['say "hi"', '"']];
        yield 'backslashes'    => [['a\\b', '\\', '\\\\']];
        yield 'braces'         => [['{', '}', '{x}']];
        yield 'spaces'         => [[' leading', 'trailing ', 'in between']];
        yield 'unicode'        => [['метка', '日本語', 'emoji 🌲']];
        yield 'empty string'   => [['']];
        yield 'nasty mix'      => [['", \\ {a,b}', 'NULL', '']];
    }

    /** @param list<string> $labels */
    #[Test]
    #[DataProvider('roundTripArrays')]
    public function encodeDecodeRoundTripPreservesElements(array $labels): void
    {
        $row = $this->hydrator->dehydrate(new LabeledRow('r1', 'title', $labels));

        self::assertSame($labels, $this->hydrator->hydrate(LabeledRow::class, $row)->labels);
    }

    // ---- entity dehydrate/hydrate ------------------------------------------

    #[Test]
    public function dehydrateEncodesArrayAndHonoursColumnName(): void
    {
        $row = $this->hydrator->dehydrate(new LabeledRow('r1', 'title', ['a', 'b'], ['x']));

        self::assertSame('{"a","b"}', $row['labels']);
        self::assertSame('{"x"}', $row['extra_labels']);   // #[PgArray(name:)] honoured
    }

    #[Test]
    public function nullableArrayColumnStaysNull(): void
    {
        $row = $this->hydrator->dehydrate(new LabeledRow('r1', 'title', []));
        self::assertNull($row['extra_labels']);

        $entity = $this->hydrator->hydrate(LabeledRow::class, [
            'id'           => 'r1',
            'title'        => 'title',
            'labels'       => '{}',
            'extra_labels' => null,
        ]);
        self::assertNull($entity->extraLabels);
    }

    // ---- $where guard -------------------------------------------------------

    #[Test]
    public function arrayValueInWhereThrowsAClearErrorInsteadOfFailingMysteriously(): void
    {
        $repo = new LabeledRowRepository(
            new UnreachablePdo(),
            $this->dialect,
            $this->hydrator,
            new IdGenerator(),
            new WorkerLifecycle(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/equality filters on array columns are not supported/');

        $repo->firstWhere(['labels' => ['a', 'b']]);
    }

    #[Test]
    public function repositoryExposesTheLiteralHelperForCustomContainmentSql(): void
    {
        $repo = new LabeledRowRepository(
            new UnreachablePdo(),
            $this->dialect,
            $this->hydrator,
            new IdGenerator(),
            new WorkerLifecycle(),
        );

        self::assertSame('{"a","b,c"}', $repo->encodeLabels(['a', 'b,c']));
    }

    #[Test]
    public function toBindableRejectsArrays(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Cannot bind a PHP array/');

        $this->hydrator->toBindable(['a']);
    }

    private function row(string $labels): LabeledRow
    {
        return $this->hydrator->hydrate(LabeledRow::class, [
            'id'     => 'r1',
            'title'  => 'title',
            'labels' => $labels,
        ]);
    }
}

/** The filter guard must fire before any SQL is prepared — this PDO has no connection at all. */
final class UnreachablePdo extends PDO
{
    public function __construct()
    {
        // Intentionally skip parent constructor — no real connection.
    }
}
