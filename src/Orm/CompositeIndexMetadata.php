<?php

namespace Pinecore\Pinecore\Orm;

/**
 * Class-level multi-column index. Columns are already resolved to their storage
 * names by EntityMap. SQL-only — the Mongo branch never reads these.
 */
final class CompositeIndexMetadata
{
    /** @param list<string> $columns */
    public function __construct(
        public readonly array   $columns,
        public readonly bool    $unique = false,
        public readonly ?string $name = null,
        public readonly ?string $using = null,
    ) {}
}
