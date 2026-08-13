<?php

namespace Pinecore\Pinecore\Orm\Attributes;

use Attribute;

/**
 * Maps a property to a plain column.
 *
 * @param ?string $name     Column name; defaults to the property name.
 * @param ?string $readExpr SQL expression wrapping the column on SELECT, with `%s`
 *                          standing for the already-quoted column name — e.g.
 *                          `'ST_AsEWKT(%s)'`. The result is aliased back to the
 *                          column name, so hydration is unaffected. SQL-only.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Field
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $readExpr = null,
    ) {}
}
