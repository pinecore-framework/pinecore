<?php

namespace Pinecore\Pinecore\Orm\Attributes;

use Attribute;

/**
 * Marks a property as a PostGIS geometry column. Shorthand for a #[Field] that
 * needs both a read expression and a GiST index:
 *
 *  - on SELECT the column is wrapped in $readExpr (default `ST_AsEWKT(%s)`), so
 *    a string property receives WKT instead of the raw HEXEWKB the driver returns;
 *  - `pg:ensure-indexes` emits `CREATE INDEX … USING gist (col)` unless $index
 *    is false.
 *
 * Writing needs no expression: PostgreSQL applies the column type's input
 * function to a bound EWKT string (`SRID=4326;POINT(37.6 55.7)`) as-is.
 *
 * $srid/$type are declarative metadata for the application (DDL lives outside
 * the framework); they do not alter generated SQL.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Geometry
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly int $srid = 4326,
        public readonly string $type = 'Geometry',
        public readonly bool $index = true,
        public readonly ?string $readExpr = 'ST_AsEWKT(%s)',
    ) {}
}
