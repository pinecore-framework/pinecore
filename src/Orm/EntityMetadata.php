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
