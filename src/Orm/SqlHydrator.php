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
                } elseif ($value instanceof \DateTimeInterface) {
                    $value = \DateTimeImmutable::createFromInterface($value)
                        ->setTimezone(new \DateTimeZone('UTC'))
                        ->format(\DATE_ATOM);
                }
            }

            $row[$field->field] = $value;
        }

        return $row;
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
}
