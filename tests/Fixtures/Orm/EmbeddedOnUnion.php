<?php

namespace Tests\Pronchev\Pinecore\Fixtures\Orm;

use Pronchev\Pinecore\Model\MongoEntity;
use Pronchev\Pinecore\Orm\Attributes\Collection;
use Pronchev\Pinecore\Orm\Attributes\Embedded;
use Pronchev\Pinecore\Orm\Attributes\Id;

#[Collection(name: 'invalid_embedded_union')]
final class EmbeddedOnUnion implements MongoEntity
{
    public function __construct(
        #[Id]       public readonly string                       $id,
        #[Embedded] public readonly EmbeddedAddress|SimpleUser   $payload,
    ) {}
}
