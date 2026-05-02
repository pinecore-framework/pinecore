<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Model\MongoEntity;
use Pinecore\Pinecore\Orm\Attributes\Collection;
use Pinecore\Pinecore\Orm\Attributes\Field;

#[Collection(name: 'no_id_entities')]
final class EntityWithoutId implements MongoEntity
{
    public function __construct(
        #[Field] public readonly string $email,
    ) {}
}
