<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Model\MongoEntity;
use Pinecore\Pinecore\Orm\Attributes\Collection;
use Pinecore\Pinecore\Orm\Attributes\EmbeddedList;
use Pinecore\Pinecore\Orm\Attributes\Id;

#[Collection(name: 'invalid_embedded_list_missing')]
final class EmbeddedListWithMissingClass implements MongoEntity
{
    public function __construct(
        #[Id]                                                public readonly string $id,
        #[EmbeddedList(class: 'App\\NonExistentEmbeddedItem')] public readonly array $items = [],
    ) {}
}
