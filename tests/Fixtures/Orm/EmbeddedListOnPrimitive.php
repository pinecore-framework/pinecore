<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Model\MongoEntity;
use Pinecore\Pinecore\Orm\Attributes\Collection;
use Pinecore\Pinecore\Orm\Attributes\EmbeddedList;
use Pinecore\Pinecore\Orm\Attributes\Id;

#[Collection(name: 'invalid_embedded_list_primitive')]
final class EmbeddedListOnPrimitive implements MongoEntity
{
    public function __construct(
        #[Id]                                          public readonly string $id,
        #[EmbeddedList(class: EmbeddedAddress::class)] public readonly string $items = '',
    ) {}
}
