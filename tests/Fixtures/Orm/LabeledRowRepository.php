<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Orm\AbstractSqlRepository;
use Pinecore\Pinecore\Orm\Attributes\ForEntity;

#[ForEntity(LabeledRow::class)]
final class LabeledRowRepository extends AbstractSqlRepository
{
    /** @param array<string, mixed> $where */
    public function firstWhere(array $where): ?LabeledRow
    {
        return $this->findOneWhere($where);
    }

    /**
     * Exposes the protected literal helper for custom containment SQL.
     *
     * @param list<string> $labels
     */
    public function encodeLabels(array $labels): string
    {
        return $this->arrayLiteral($labels);
    }
}
