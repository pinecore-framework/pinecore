<?php

namespace Tests\Pronchev\Pinecore\Fixtures\Orm;

use Pronchev\Pinecore\Model\MongoEntity;
use Pronchev\Pinecore\Orm\Attributes\Collection;
use Pronchev\Pinecore\Orm\Attributes\Embedded;
use Pronchev\Pinecore\Orm\Attributes\Id;

#[Collection(name: 'invalid_embedded_primitive')]
final class EmbeddedOnPrimitive implements MongoEntity
{
    public function __construct(
        #[Id]       public readonly string $id,
        #[Embedded] public readonly string $payload,
    ) {}
}
