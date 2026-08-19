<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Model\SqlEntity;
use Pinecore\Pinecore\Orm\Attributes\Field;
use Pinecore\Pinecore\Orm\Attributes\Id;
use Pinecore\Pinecore\Orm\Attributes\Index;
use Pinecore\Pinecore\Orm\Attributes\PgArray;
use Pinecore\Pinecore\Orm\Attributes\Table;

/**
 * text[]-shaped fixture: a labels column under a GIN index (the tillit
 * access-labels use case) plus a renamed nullable array column. Only the
 * hydrator conversions and the generated DDL are asserted — never executed
 * against a real Postgres.
 */
#[Table(name: 'labeled_rows')]
final class LabeledRow implements SqlEntity
{
    public function __construct(
        #[Id]
        public readonly string $id,

        #[Field]
        public readonly string $title,

        #[PgArray]
        #[Index(using: 'gin')]
        public readonly array $labels = [],

        #[PgArray(name: 'extra_labels')]
        public readonly ?array $extraLabels = null,
    ) {}
}
