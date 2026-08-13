<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Orm\AbstractSqlRepository;
use Pinecore\Pinecore\Orm\Attributes\ForEntity;

#[ForEntity(ExprRow::class)]
final class ExprRowRepository extends AbstractSqlRepository
{
    public function save(ExprRow $row): ExprRow
    {
        return $this->persist($row);
    }

    public function find(string $id): ?ExprRow
    {
        return $this->findById($id);
    }

    public function byLabel(string $label): ?ExprRow
    {
        return $this->findOneWhere(['label' => $label]);
    }
}
