<?php

namespace Pinecore\Pinecore\Orm\Attributes;

use Attribute;

/**
 * Multi-column index declared at class level, for cases #[Index] cannot express:
 *
 *     #[Table(name: 'place_external_ref')]
 *     #[CompositeIndex(columns: ['source', 'external_id'], unique: true)]
 *
 * Entries in $columns may be either column names or property names — property
 * names are resolved to their mapped column, anything else is used verbatim.
 *
 * SQL-only: the Mongo branch ignores class-level composite indexes.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class CompositeIndex
{
    /**
     * @param list<string> $columns
     * @param ?string      $using Index method ('btree'|'hash'|'gist'|'spgist'|'gin'|'brin');
     *                            null = engine default (B-tree).
     */
    public function __construct(
        public readonly array   $columns,
        public readonly bool    $unique = false,
        public readonly ?string $name = null,
        public readonly ?string $using = null,
    ) {}
}
