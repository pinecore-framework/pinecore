<?php

namespace Tests\Pronchev\Pinecore\Fixtures\Orm;

use Pronchev\Pinecore\Model\MongoEntity;
use Pronchev\Pinecore\Orm\Attributes\Collection;
use Pronchev\Pinecore\Orm\Attributes\Field;
use Pronchev\Pinecore\Orm\Attributes\Id;

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
