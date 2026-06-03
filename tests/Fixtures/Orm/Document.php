<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Model\SqlEntity;
use Pinecore\Pinecore\Orm\Attributes\Field;
use Pinecore\Pinecore\Orm\Attributes\Id;
use Pinecore\Pinecore\Orm\Attributes\Json;
use Pinecore\Pinecore\Orm\Attributes\Table;
use Pinecore\Pinecore\Orm\Attributes\Vector;

#[Table(name: 'documents')]
final class Document implements SqlEntity
{
    /**
     * @param list<float>          $embedding
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        #[Id]                       public readonly string  $id,
        #[Field(name: 'tenant_id')] public readonly string  $tenantId,
        #[Field]                    public readonly string  $title,
        #[Vector(dim: 3)]           public readonly array   $embedding,
        #[Field]                    public readonly ?string $status   = null,
        #[Json]                     public readonly array   $metadata = [],
    ) {}
}
