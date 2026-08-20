<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Model\SqlEntity;
use Pinecore\Pinecore\Orm\Attributes\Field;
use Pinecore\Pinecore\Orm\Attributes\Id;
use Pinecore\Pinecore\Orm\Attributes\Table;

/** Primary key column named differently from the property: links.object_id ↔ $objectId. */
#[Table(name: 'links')]
final class LinkRow implements SqlEntity
{
    public function __construct(
        #[Id(name: 'object_id')]    public readonly string  $objectId,
        #[Field(name: 'class_id')]  public readonly string  $classId,
        #[Field]                    public readonly ?string $title = null,
    ) {}
}
