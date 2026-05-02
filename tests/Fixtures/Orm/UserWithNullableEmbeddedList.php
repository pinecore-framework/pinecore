<?php

namespace Tests\Pronchev\Pinecore\Fixtures\Orm;

use Pronchev\Pinecore\Model\MongoEntity;
use Pronchev\Pinecore\Orm\Attributes\Collection;
use Pronchev\Pinecore\Orm\Attributes\EmbeddedList;
use Pronchev\Pinecore\Orm\Attributes\Field;
use Pronchev\Pinecore\Orm\Attributes\Id;

#[Collection(name: 'users_with_optional_addresses')]
final class UserWithNullableEmbeddedList implements MongoEntity
{
    public function __construct(
        #[Id]                                          public readonly string  $id,
        #[Field]                                       public readonly string  $email,
        #[EmbeddedList(class: EmbeddedAddress::class)] public readonly ?array  $addresses = null,
    ) {}
}
