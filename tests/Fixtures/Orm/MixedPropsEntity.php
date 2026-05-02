<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Model\MongoEntity;
use Pinecore\Pinecore\Orm\Attributes\Collection;
use Pinecore\Pinecore\Orm\Attributes\Field;
use Pinecore\Pinecore\Orm\Attributes\Id;

#[Collection(name: 'mixed_props')]
final class MixedPropsEntity implements MongoEntity
{
    public function __construct(
        #[Id]    public readonly string $id,
        #[Field] public readonly string $email,
                 public readonly string $untagged = 'ignored',
    ) {}
}
