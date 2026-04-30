<?php

namespace Tests\Pronchev\Pinecore\Fixtures\Orm;

use Pronchev\Pinecore\Model\MongoEntity;
use Pronchev\Pinecore\Orm\Attributes\Collection;
use Pronchev\Pinecore\Orm\Attributes\Field;
use Pronchev\Pinecore\Orm\Attributes\Id;
use Pronchev\Pinecore\Orm\Attributes\Index;

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
