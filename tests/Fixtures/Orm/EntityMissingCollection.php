<?php

namespace Tests\Pronchev\Pinecore\Fixtures\Orm;

use Pronchev\Pinecore\Model\MongoEntity;
use Pronchev\Pinecore\Orm\Attributes\Field;
use Pronchev\Pinecore\Orm\Attributes\Id;

final class EntityMissingCollection implements MongoEntity
{
    public function __construct(
        #[Id]    public readonly string $id,
        #[Field] public readonly string $email,
    ) {}
}
