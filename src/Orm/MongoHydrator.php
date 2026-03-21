<?php

namespace Pronchev\Pinecore\Orm;

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
            $value = $doc[$key] ?? null;

            if ($value instanceof \MongoDB\Model\BSONArray) {
                $value = $value->getArrayCopy();
            }

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
     * Convert a typed entity to an array suitable for MongoDB storage.
     */
    public function dehydrate(object $entity): array
    {
        $map = EntityMap::of($entity::class);
        $doc = [];

        foreach ($map->fields as $field) {
            $value = $field->property->getValue($entity);

            if ($field->isEmbedded && $value !== null) {
                $value = $this->dehydrateEmbedded($value);
            } elseif ($field->isEmbeddedList && is_array($value)) {
                $value = array_map(fn($item) => $this->dehydrateEmbedded($item), $value);
            }

            $doc[$field->field] = $value;
        }

        return $doc;
    }

    private function dehydrateEmbedded(object $embedded): array
    {
        $result = [];
        $ref = new \ReflectionClass($embedded);
        foreach ($ref->getProperties() as $property) {
            $result[$property->getName()] = $property->getValue($embedded);
        }
        return $result;
    }

    private function toArray(array|BSONDocument $doc): array
    {
        if ($doc instanceof BSONDocument) {
            return iterator_to_array($doc);
        }
        return $doc;
    }
}
