<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Model\SqlEntity;
use Pinecore\Pinecore\Orm\Attributes\CompositeIndex;
use Pinecore\Pinecore\Orm\Attributes\Field;
use Pinecore\Pinecore\Orm\Attributes\Geometry;
use Pinecore\Pinecore\Orm\Attributes\Id;
use Pinecore\Pinecore\Orm\Attributes\Index;
use Pinecore\Pinecore\Orm\Attributes\Table;

/**
 * PostGIS-shaped fixture: read expressions on geometry, a class-level composite
 * unique index and an explicitly declared GiST index. Never executed against
 * SQLite — only the generated SQL is asserted.
 */
#[Table(name: 'places')]
#[CompositeIndex(columns: ['source', 'externalId'], unique: true)]          // property name → column
#[CompositeIndex(columns: ['context_hash', 'place_id'], name: 'idx_ctx')]   // raw column names
final class GeoPlace implements SqlEntity
{
    public function __construct(
        #[Id]
        public readonly string $id,

        #[Field]
        public readonly string $source,

        #[Field(name: 'external_id')]
        public readonly string $externalId,

        #[Geometry(srid: 4326, type: 'Point')]
        public readonly ?string $geom = null,

        // Explicit method on a single-column index; also exercises `using`.
        #[Field(name: 'bbox')]
        #[Index(using: 'gist')]
        public readonly ?string $bbox = null,
    ) {}
}
