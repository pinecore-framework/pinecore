<?php

namespace Pinecore\Pinecore\Orm;

use Pinecore\Pinecore\Orm\Sql\SqlDialect;

/**
 * Converts SQL rows to typed entities and back. Mirrors MongoHydrator, but the
 * two storage-specific conversions are vectors (float[] <-> dialect literal) and
 * JSON columns (PHP value <-> encoded string). Plain columns pass through.
 */
final class SqlHydrator
{
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
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(\DATE_ATOM);
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
