<?php

namespace Tests\Pronchev\Pinecore\Fixtures\Orm;

use Pronchev\Pinecore\Model\MongoEntity;
use Pronchev\Pinecore\Orm\Attributes\Collection;
use Pronchev\Pinecore\Orm\Attributes\Field;

#[Collection(name: 'no_id_entities')]
final class EntityWithoutId implements MongoEntity
{
    public function __construct(
        #[Field] public readonly string $email,
    ) {}
}
