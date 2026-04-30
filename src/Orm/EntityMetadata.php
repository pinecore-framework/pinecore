<?php

namespace Pronchev\Pinecore\Orm;

final class EntityMetadata
{
    /**
     * @param FieldMetadata[] $fields
     */
    public function __construct(
        public readonly ?string $collectionName,
        public readonly array $fields,
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
