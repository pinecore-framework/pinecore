<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Model\MongoEntity;
use Pinecore\Pinecore\Orm\Attributes\Collection;
use Pinecore\Pinecore\Orm\Attributes\Field;
use Pinecore\Pinecore\Orm\Attributes\Id;
use Pinecore\Pinecore\Orm\Attributes\Index;

#[Collection(name: 'indexed_no_field')]
final class IndexedEntityNoField implements MongoEntity
{
    public function __construct(
        #[Id]
        public readonly string $id,

        // #[Index] without #[Field] — must throw LogicException at metadata time
        #[Index]
        public readonly string $orphan,
    ) {}
}
