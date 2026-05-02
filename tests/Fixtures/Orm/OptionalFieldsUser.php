<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Model\MongoEntity;
use Pinecore\Pinecore\Orm\Attributes\Collection;
use Pinecore\Pinecore\Orm\Attributes\Field;
use Pinecore\Pinecore\Orm\Attributes\Id;

#[Collection(name: 'optional_fields_users')]
final class OptionalFieldsUser implements MongoEntity
{
    public function __construct(
        #[Id]    public readonly string  $id,
        #[Field] public readonly string  $email,           // required, non-nullable, no default
        #[Field] public readonly ?string $note,            // nullable, no default
        #[Field] public readonly string  $name = 'guest',  // non-nullable, has default
    ) {}
}
