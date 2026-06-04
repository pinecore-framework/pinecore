<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Model\SqlEntity;
use Pinecore\Pinecore\Orm\Attributes\Field;
use Pinecore\Pinecore\Orm\Attributes\Id;
use Pinecore\Pinecore\Orm\Attributes\Table;

#[Table(name: 'profiles')]
final class ScalarRow implements SqlEntity
{
    public function __construct(
        #[Id]                        public readonly string $id,
        #[Field(name: 'is_visible')] public readonly bool   $isVisible,
        #[Field(name: 'view_count')] public readonly int    $viewCount,
        #[Field]                     public readonly float  $score,
        #[Field(name: 'is_pinned')]  public readonly ?bool  $isPinned = null,
    ) {}
}
