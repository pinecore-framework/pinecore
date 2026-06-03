<?php

namespace Pinecore\Pinecore\Orm\Attributes;

use Attribute;

/**
 * Marks a float[] property as a pgvector column.
 *
 * @param int    $dim    Vector dimensionality; must match the column definition.
 * @param string $metric Distance metric: 'cosine' (<=>), 'l2' (<->), 'ip' (<#>).
 *                       Must match the opclass of the HNSW/IVFFlat index, or the
 *                       index is silently bypassed (seq scan).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Vector
{
    public function __construct(
        public readonly int    $dim,
        public readonly string $metric = 'cosine',
    ) {}
}
