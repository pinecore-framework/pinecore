<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Model\SqlEntity;
use Pinecore\Pinecore\Orm\Attributes\Id;
use Pinecore\Pinecore\Orm\Attributes\Json;
use Pinecore\Pinecore\Orm\Attributes\Table;

/** Fixture for #[Json(asObject:)] — a map column next to a plain JSON column. */
#[Table(name: 'props_rows')]
final class PropsRow implements SqlEntity
{
    /**
     * @param array<string, mixed> $props
     * @param array<int|string, mixed> $metadata
     */
    public function __construct(
        #[Id]                   public readonly string $id,
        #[Json(asObject: true)] public readonly array  $props    = [],
        #[Json]                 public readonly array  $metadata = [],
    ) {}
}
