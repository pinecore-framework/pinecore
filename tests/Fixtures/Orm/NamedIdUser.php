<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Model\MongoEntity;
use Pinecore\Pinecore\Orm\Attributes\Collection;
use Pinecore\Pinecore\Orm\Attributes\Field;
use Pinecore\Pinecore\Orm\Attributes\Id;

/** Mongo entity whose id is stored under a custom document key. */
#[Collection(name: 'accounts')]
final class NamedIdUser implements MongoEntity
{
    public function __construct(
        #[Id(name: 'user_id')] public readonly string $userId,
        #[Field]               public readonly string $email,
    ) {}
}
