<?php

namespace Tests\Pronchev\Pinecore\Fixtures\Orm;

use Pronchev\Pinecore\Model\MongoEntity;
use Pronchev\Pinecore\Orm\Attributes\Collection;
use Pronchev\Pinecore\Orm\Attributes\Field;
use Pronchev\Pinecore\Orm\Attributes\Id;

#[Collection(name: 'mixed_props')]
final class MixedPropsEntity implements MongoEntity
{
    public function __construct(
        #[Id]    public readonly string $id,
        #[Field] public readonly string $email,
                 public readonly string $untagged = 'ignored',
    ) {}
}
