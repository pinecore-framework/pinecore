<?php

namespace Pinecore\Pinecore\Orm\Attributes;

use Attribute;

/**
 * Marks a list<string> property as a PostgreSQL text[] column. Postgres-only,
 * like #[Vector]/#[Geometry] — a non-PG dialect would throw on the literal.
 *
 * Only text element types are supported. Elements must be strings — a null or
 * non-string element is rejected on write rather than coerced, and a NULL token
 * is rejected on read: labels-style semantics have no use for NULL-in-array,
 * and a silent NULL there is a source of quiet bugs. Multidimensional arrays
 * are out of scope.
 *
 * Equality filters ($where) do not work on array columns; containment queries
 * (@>, &&) are hand-written SQL via execute() with SqlDialect::arrayLiteral().
 *
 * @param ?string $name Column name; defaults to the property name.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class PgArray
{
    public function __construct(
        public readonly ?string $name = null,
    ) {}
}
