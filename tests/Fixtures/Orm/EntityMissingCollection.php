<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Model\MongoEntity;
use Pinecore\Pinecore\Orm\Attributes\Field;
use Pinecore\Pinecore\Orm\Attributes\Id;

final class EntityMissingCollection implements MongoEntity
{
    public function __construct(
        #[Id]    public readonly string $id,
        #[Field] public readonly string $email,
    ) {}
}
