<?php

namespace Pinecore\Pinecore\Orm;

final class EntityMetadata
{
    /**
     * @param FieldMetadata[]          $fields
     * @param IndexMetadata[]          $indexes
     * @param CompositeIndexMetadata[] $compositeIndexes
     */
    public function __construct(
        public readonly ?string $storageName,
        public readonly array $fields,
        public readonly array $indexes = [],
        public readonly array $compositeIndexes = [],
    ) {}

    public function idField(): FieldMetadata
    {
        foreach ($this->fields as $field) {
            if ($field->isId) {
                return $field;
            }
        }
        throw new \LogicException('No #[Id] field found in entity metadata.');
    }

    /**
     * Looks a field up by either its property name or its storage name (column /
     * document key) — the same dual resolution #[CompositeIndex] columns use.
     * Property names win on a clash. Returns null for anything unknown, so callers
     * can refuse to splice the key into a query.
     */
    public function fieldFor(string $key): ?FieldMetadata
    {
        foreach ($this->fields as $field) {
            if ($field->property->getName() === $key) {
                return $field;
            }
        }
        foreach ($this->fields as $field) {
            if ($field->field === $key) {
                return $field;
            }
        }
        return null;
    }

    /**
     * Fields backed by a real SQL column, in declaration order.
     *
     * @return list<FieldMetadata>
     */
    public function sqlFields(): array
    {
        return array_values(array_filter($this->fields, static fn(FieldMetadata $f): bool => $f->isSqlColumn()));
    }

    public static function isNewId(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
