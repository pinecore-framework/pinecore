<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Model\SqlEntity;
use Pinecore\Pinecore\Orm\Attributes\Field;
use Pinecore\Pinecore\Orm\Attributes\Id;
use Pinecore\Pinecore\Orm\Attributes\Table;

/**
 * Read expressions in a dialect-neutral form, so the round trip can run on
 * SQLite. `note` deliberately carries a literal '%' — the expression must be
 * expanded with str_replace, never sprintf.
 */
#[Table(name: 'expr_rows')]
final class ExprRow implements SqlEntity
{
    public function __construct(
        #[Id]
        public readonly string $id,

        #[Field(readExpr: 'upper(%s)')]
        public readonly string $label,

        #[Field(name: 'note', readExpr: "%s || '%'")]
        public readonly ?string $note = null,
    ) {}
}
