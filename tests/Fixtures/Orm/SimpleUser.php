<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Model\MongoEntity;
use Pinecore\Pinecore\Orm\Attributes\Collection;
use Pinecore\Pinecore\Orm\Attributes\Field;
use Pinecore\Pinecore\Orm\Attributes\Id;

#[Collection(name: 'simple_users')]
final class SimpleUser implements MongoEntity
{
    public function __construct(
        #[Id]                          public readonly string  $id,
        #[Field]                       public readonly string  $email,
        #[Field(name: 'display_name')] public readonly ?string $displayName = null,
    ) {}
}
