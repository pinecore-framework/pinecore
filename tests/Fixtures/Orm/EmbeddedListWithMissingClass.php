<?php

namespace Tests\Pronchev\Pinecore\Fixtures\Orm;

use Pronchev\Pinecore\Model\MongoEntity;
use Pronchev\Pinecore\Orm\Attributes\Collection;
use Pronchev\Pinecore\Orm\Attributes\EmbeddedList;
use Pronchev\Pinecore\Orm\Attributes\Id;

#[Collection(name: 'invalid_embedded_list_missing')]
final class EmbeddedListWithMissingClass implements MongoEntity
{
    public function __construct(
        #[Id]                                                public readonly string $id,
        #[EmbeddedList(class: 'App\\NonExistentEmbeddedItem')] public readonly array $items = [],
    ) {}
}
