<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Model\SqlEntity;
use Pinecore\Pinecore\Orm\Attributes\Field;
use Pinecore\Pinecore\Orm\Attributes\Id;
use Pinecore\Pinecore\Orm\Attributes\Table;

#[Table(name: 'events')]
final class TimestampedRow implements SqlEntity
{
    public function __construct(
        #[Id]                        public readonly string             $id,
        #[Field(name: 'created_at')] public readonly \DateTimeImmutable  $createdAt,
        #[Field(name: 'deleted_at')] public readonly ?\DateTimeImmutable $deletedAt = null,
    ) {}
}
