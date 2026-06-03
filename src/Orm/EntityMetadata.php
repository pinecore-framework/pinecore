<?php

namespace Pinecore\Pinecore\Orm;

final class EntityMetadata
{
    /**
     * @param FieldMetadata[] $fields
     * @param IndexMetadata[] $indexes
     */
    public function __construct(
        public readonly ?string $storageName,
        public readonly array $fields,
        public readonly array $indexes = [],
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

    public static function isNewId(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
