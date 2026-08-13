<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Model\SqlEntity;
use Pinecore\Pinecore\Orm\Attributes\Field;
use Pinecore\Pinecore\Orm\Attributes\Id;
use Pinecore\Pinecore\Orm\Attributes\Index;
use Pinecore\Pinecore\Orm\Attributes\Table;

/** #[Index(using:)] naming a method PostgreSQL does not ship. */
#[Table(name: 'bad_method')]
final class BadIndexMethodEntity implements SqlEntity
{
    public function __construct(
        #[Id]
        public readonly string $id = '',

        #[Field]
        #[Index(using: 'rtree')]
        public readonly string $shape = '',
    ) {}
}
