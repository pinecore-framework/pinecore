<?php

namespace Tests\Pronchev\Pinecore\Fixtures\Orm;

use Pronchev\Pinecore\Model\MongoEntity;
use Pronchev\Pinecore\Orm\Attributes\Collection;
use Pronchev\Pinecore\Orm\Attributes\Field;
use Pronchev\Pinecore\Orm\Attributes\Id;

#[Collection(name: 'simple_users')]
final class SimpleUser implements MongoEntity
{
    public function __construct(
        #[Id]                          public readonly string  $id,
        #[Field]                       public readonly string  $email,
        #[Field(name: 'display_name')] public readonly ?string $displayName = null,
    ) {}
}
