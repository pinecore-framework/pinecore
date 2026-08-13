<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Orm\AbstractSqlRepository;
use Pinecore\Pinecore\Orm\Attributes\ForEntity;

#[ForEntity(ScalarRow::class)]
final class ScalarRowRepository extends AbstractSqlRepository
{
    public function save(ScalarRow $row): ScalarRow
    {
        return $this->persist($row);
    }

    /** @param iterable<ScalarRow> $rows */
    public function saveAll(iterable $rows): int
    {
        return $this->persistAll($rows);
    }

    public function find(string $id): ?ScalarRow
    {
        return $this->findById($id);
    }

    /** @param array<string, mixed> $where */
    public function firstWhere(array $where): ?ScalarRow
    {
        return $this->findOneWhere($where);
    }

    /** @return array<string, mixed> */
    public function columnsOf(ScalarRow $row): array
    {
        return $this->dehydrateEntity($row);
    }

    /** Custom SQL that normalises its own loose parameters. */
    public function countVisible(bool $visible): int
    {
        return (int) $this->execute(
            'SELECT COUNT(*) FROM profiles WHERE is_visible = :v',
            ['v' => $this->bindable($visible)],
        )->fetchColumn();
    }
}
