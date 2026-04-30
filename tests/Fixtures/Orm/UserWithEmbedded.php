<?php

namespace Tests\Pronchev\Pinecore\Fixtures\Orm;

use Pronchev\Pinecore\Model\MongoEntity;
use Pronchev\Pinecore\Orm\Attributes\Collection;
use Pronchev\Pinecore\Orm\Attributes\Embedded;
use Pronchev\Pinecore\Orm\Attributes\Field;
use Pronchev\Pinecore\Orm\Attributes\Id;

#[Collection(name: 'users_with_address')]
final class UserWithEmbedded implements MongoEntity
{
    public function __construct(
        #[Id]       public readonly string           $id,
        #[Field]    public readonly string           $email,
        #[Embedded] public readonly EmbeddedAddress  $address,
    ) {}
}
