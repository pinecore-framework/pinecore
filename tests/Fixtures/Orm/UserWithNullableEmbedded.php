<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Model\MongoEntity;
use Pinecore\Pinecore\Orm\Attributes\Collection;
use Pinecore\Pinecore\Orm\Attributes\Embedded;
use Pinecore\Pinecore\Orm\Attributes\Field;
use Pinecore\Pinecore\Orm\Attributes\Id;

#[Collection(name: 'users_with_optional_address')]
final class UserWithNullableEmbedded implements MongoEntity
{
    public function __construct(
        #[Id]       public readonly string            $id,
        #[Field]    public readonly string            $email,
        #[Embedded] public readonly ?EmbeddedAddress  $address = null,
    ) {}
}
