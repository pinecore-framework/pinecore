<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Model\MongoEntity;
use Pinecore\Pinecore\Orm\Attributes\Collection;
use Pinecore\Pinecore\Orm\Attributes\Embedded;
use Pinecore\Pinecore\Orm\Attributes\Id;

#[Collection(name: 'invalid_embedded_untyped')]
final class EmbeddedOnUntypedProperty implements MongoEntity
{
    #[Embedded]
    public $payload;

    public function __construct(
        #[Id] public readonly string $id,
    ) {}
}
