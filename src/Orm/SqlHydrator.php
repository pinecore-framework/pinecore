<?php

namespace Pinecore\Pinecore\Orm;

use Pinecore\Pinecore\Orm\Sql\SqlDialect;

/**
 * Converts SQL rows to typed entities and back. Mirrors MongoHydrator, but the
 * storage-specific conversions are vectors (float[] <-> dialect literal),
 * text[] arrays (list<string> <-> dialect literal) and JSON columns (PHP value
 * <-> encoded string). Plain columns pass through.
 */
final class SqlHydrator
{
    /**
     * Wire format for a DateTimeInterface bound as an SQL parameter.
     *
     * Deliberately NOT \DATE_ATOM: that format ends at whole seconds, so binding
     * through it silently truncated every value to 1s while the `timestamptz`
     * column behind it stores microseconds. Nothing reported the loss — the write
     * succeeded and the read parsed fine, the instant was just not the one that
     * was saved, which broke ordering, equality filters and any use of a
     * timestamp as a concurrency version. Six fractional digits is what
     * PostgreSQL keeps; asking for more would only be rounded away again.
     *
     * If a second SqlDialect ever appears, this belongs there — the literal
     * format is a property of the database, not of the hydrator.
     */
    private const DATETIME_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        private readonly SqlDialect $dialect,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public function hydrate(string $class, array $row): object
    {
        $map  = EntityMap::of($class);
        $args = [];

        foreach ($map->fields as $field) {
            $key      = $field->field;
            $propName = $field->property->getName();

            if (!array_key_exists($key, $row)) {
                if ($field->hasConstructorDefault) {
                    continue;
                }
                if ($field->isNullable) {
                    $args[$propName] = null;
                    continue;
                }
                throw new \LogicException(
                    "Missing required column '{$key}' for {$class} during hydration."
                );
            }

            $value = $row[$key];

            if ($value !== null) {
                if ($field->isVector) {
                    $value = $this->parseVector($value);
                } elseif ($field->isPgArray) {
                    $value = is_array($value) ? $value : $this->parsePgArray((string) $value, $field->field);
                } elseif ($field->isJson) {
                    $value = is_string($value) ? json_decode($value, true) : $value;
                } elseif ($this->isDateTime($field)) {
                    $value = new \DateTimeImmutable((string) $value);
                    $value = $value->setTimezone(new \DateTimeZone('UTC'));
                } else {
                    $value = $this->coerceScalar($field, $value);
                }
            }

            $args[$propName] = $value;
        }

        return new $class(...$args);
    }

    /**
     * @return array<string, mixed> column name => bindable value
     */
    public function dehydrate(object $entity): array
    {
        $map = EntityMap::of($entity::class);
        $row = [];

        foreach ($map->fields as $field) {
            $value = $field->property->getValue($entity);

            if ($value !== null) {
                if ($field->isVector) {
                    $value = $this->dialect->vectorLiteral($value);
                } elseif ($field->isPgArray) {
                    $value = $this->dialect->arrayLiteral($value);
                } elseif ($field->isJson) {
                    $value = json_encode($value);
                } else {
                    $value = $this->toBindable($value);
                }
            }

            $row[$field->field] = $value;
        }

        return $row;
    }

    /**
     * Normalises a single PHP value into something PDO can bind.
     *
     * Parameters passed to PDOStatement::execute() as an array are all bound as
     * PARAM_STR, which mangles two types: `false` becomes '' (rejected by a
     * boolean column, silently stored as an empty string by a text one), and a
     * DateTimeInterface has no string form at all. Everything else passes through.
     *
     * Shared by dehydrate() and the repository's WHERE builder, so entity writes
     * and equality filters agree on the wire format of a value.
     */
    public function toBindable(mixed $value): mixed
    {
        // PDO would cast an array to the useless string 'Array' (with a notice);
        // rejecting it here turns a mysterious non-match into an explicit error.
        // Equality on array columns is not supported anyway — containment (@>,
        // &&) is hand-written SQL via execute() with SqlDialect::arrayLiteral().
        if (is_array($value)) {
            throw new \InvalidArgumentException(
                'Cannot bind a PHP array as an SQL parameter; equality filters on array columns are not supported'
                . ' — write containment SQL via execute() and encode the value with SqlDialect::arrayLiteral()'
            );
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(self::DATETIME_FORMAT);
        }

        if (is_bool($value)) {
            return $this->dialect->boolLiteral($value);
        }

        return $value;
    }

    /**
     * @param string|array<int, mixed> $value
     * @return list<float>
     */
    private function parseVector(string|array $value): array
    {
        if (is_array($value)) {
            return array_map(static fn($v): float => (float) $v, array_values($value));
        }

        $trimmed = trim($value, '[]');
        if ($trimmed === '') {
            return [];
        }

        return array_map(static fn(string $v): float => (float) $v, explode(',', $trimmed));
    }

    /**
     * Parses the pdo_pgsql output format of a text[] column ('{"a","b"}').
     *
     * Postgres quotes every element that contains a delimiter, quote, brace,
     * backslash or whitespace (and the empty string), so an unquoted token never
     * contains structure characters. A bare NULL token is rejected: this ORM
     * never writes NULL elements (see SqlDialect::arrayLiteral()), so finding
     * one means the row was written outside the entity contract — surfacing it
     * beats silently hydrating a null into a list<string>. Multidimensional
     * arrays are out of scope and rejected explicitly.
     *
     * @return list<string>
     */
    private function parsePgArray(string $value, string $column): array
    {
        $len = strlen($value);
        if ($len < 2 || $value[0] !== '{' || $value[$len - 1] !== '}') {
            throw new \InvalidArgumentException(
                "Malformed text[] literal in column '{$column}': '{$value}'"
            );
        }
        if ($len === 2) {
            return [];
        }

        $items = [];
        $i     = 1;
        $end   = $len - 1;

        while ($i < $end) {
            if ($value[$i] === '{') {
                throw new \InvalidArgumentException(
                    "Multidimensional arrays are not supported by #[PgArray] (column '{$column}')"
                );
            }

            if ($value[$i] === '"') {
                $item = '';
                $i++;
                while ($i < $end && $value[$i] !== '"') {
                    if ($value[$i] === '\\') {
                        $i++;
                        if ($i >= $end) {
                            break;
                        }
                    }
                    $item .= $value[$i];
                    $i++;
                }
                if ($i >= $end) {
                    throw new \InvalidArgumentException(
                        "Malformed text[] literal in column '{$column}': unterminated quoted element"
                    );
                }
                $i++; // closing quote
                $items[] = $item;
            } else {
                $token = '';
                while ($i < $end && $value[$i] !== ',') {
                    $token .= $value[$i];
                    $i++;
                }
                if ($token === '') {
                    throw new \InvalidArgumentException(
                        "Malformed text[] literal in column '{$column}': empty unquoted element"
                    );
                }
                if (strcasecmp($token, 'NULL') === 0) {
                    throw new \InvalidArgumentException(
                        "NULL element in text[] column '{$column}'; #[PgArray] does not allow null elements"
                    );
                }
                $items[] = $token;
            }

            if ($i < $end) {
                if ($value[$i] !== ',') {
                    throw new \InvalidArgumentException(
                        "Malformed text[] literal in column '{$column}': expected ',' at offset {$i}"
                    );
                }
                $i++;
                if ($i >= $end) {
                    throw new \InvalidArgumentException(
                        "Malformed text[] literal in column '{$column}': trailing comma"
                    );
                }
            }
        }

        return $items;
    }

    /**
     * Opt-in by declared type: a plain column whose property is typed
     * DateTimeImmutable (optionally nullable) is wrapped on read. union/string
     * types are left untouched, so existing string timestamp fields keep working.
     */
    private function isDateTime(FieldMetadata $field): bool
    {
        if ($field->isVector || $field->isJson) {
            return false;
        }

        return ltrim($field->type, '?') === \DateTimeImmutable::class;
    }

    /**
     * Coerces a raw column value to the property's declared scalar type. The pgsql
     * driver (with ATTR_EMULATE_PREPARES=false) returns every column as a string
     * — including booleans as 't'/'f' — so a bool/int/float-typed property would
     * otherwise get a string ('f' coerces to true; a TypeError under strict_types).
     *
     * Only the unambiguous scalar types are touched. string/mixed/union and any
     * class type pass through unchanged, preserving existing behaviour.
     */
    private function coerceScalar(FieldMetadata $field, mixed $value): mixed
    {
        return match (ltrim($field->type, '?')) {
            'bool'  => $this->toBool($value),
            'int'   => (int) $value,
            'float' => (float) $value,
            default => $value,
        };
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }

        // Postgres text protocol yields 't'/'f'; accept common spellings too.
        return in_array(strtolower((string) $value), ['t', 'true', '1', 'y', 'yes'], true);
    }
}
