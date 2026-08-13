<?php

namespace Pinecore\Pinecore\Orm\Attributes;

use Attribute;

/**
 * Single-column index on the annotated property.
 *
 * @param ?string $using Index method for SQL backends ('btree'|'hash'|'gist'|
 *                       'spgist'|'gin'|'brin'); null = engine default (B-tree).
 *                       Ignored by the Mongo branch. `direction` is only emitted
 *                       for B-tree — other methods have no ordered form.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class Index
{
    public function __construct(
        public readonly int     $direction = 1,
        public readonly bool    $unique = false,
        public readonly bool    $sparse = false,
        public readonly ?string $name = null,
        public readonly ?int    $expireAfterSeconds = null,
        public readonly ?string $using = null,
    ) {}
}
