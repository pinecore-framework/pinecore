<?php

namespace Pinecore\Pinecore\Orm;

use MongoDB\Model\BSONDocument;

final class MongoHydrator
{
    /**
     * Convert a MongoDB document (array or BSONDocument) to a typed entity.
     */
    public function hydrate(string $class, array|BSONDocument $doc): object
    {
        $doc = $this->toArray($doc);
        $map = EntityMap::of($class);
        $args = [];

        foreach ($map->fields as $field) {
            $key = $field->field;
            $propName = $field->property->getName();

            if (!array_key_exists($key, $doc)) {
                if ($field->hasConstructorDefault) {
                    continue;
                }
                if ($field->isNullable) {
                    $args[$propName] = null;
                    continue;
                }
                throw new \LogicException(
                    "Missing required field '{$key}' for {$class} during hydration."
                );
            }

            $value = $this->normalizeBson($doc[$key]);

            if ($field->isEmbedded && $value !== null) {
                $value = $this->hydrate($field->embeddedClass, $this->toArray($value));
            } elseif ($field->isEmbeddedList && is_array($value)) {
                $value = array_map(
                    fn($item) => $this->hydrate($field->embeddedClass, $this->toArray($item)),
                    $value,
                );
            }

            $args[$propName] = $value;
        }

        return new $class(...$args);
    }

    /**
     * Convert a typed entity (or an embedded value object) to an array
     * suitable for MongoDB storage. Embedded values recurse through the
     * same path so that #[Field(name: ...)] is honoured symmetrically
     * with hydrate().
     */
    public function dehydrate(object $entity): array
    {
        $map = EntityMap::of($entity::class);
        $doc = [];

        foreach ($map->fields as $field) {
            $value = $field->property->getValue($entity);

            if ($field->isEmbedded && $value !== null) {
                $value = $this->dehydrate($value);
            } elseif ($field->isEmbeddedList && is_array($value)) {
                $value = array_map(fn($item) => $this->dehydrate($item), $value);
            }

            $doc[$field->field] = $value;
        }

        return $doc;
    }

    private function normalizeBson(mixed $value): mixed
    {
        if ($value instanceof \MongoDB\Model\BSONArray) {
            return array_map($this->normalizeBson(...), $value->getArrayCopy());
        }

        if ($value instanceof BSONDocument) {
            return array_map($this->normalizeBson(...), iterator_to_array($value));
        }

        return $value;
    }

    private function toArray(array|BSONDocument $doc): array
    {
        if ($doc instanceof BSONDocument) {
            return iterator_to_array($doc);
        }
        return $doc;
    }
}
