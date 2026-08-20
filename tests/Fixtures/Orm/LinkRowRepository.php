<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Orm\AbstractSqlRepository;
use Pinecore\Pinecore\Orm\Attributes\ForEntity;

#[ForEntity(LinkRow::class)]
final class LinkRowRepository extends AbstractSqlRepository
{
    public function save(LinkRow $row): LinkRow
    {
        return $this->persist($row);
    }

    /** @param iterable<LinkRow> $rows */
    public function saveAll(iterable $rows): int
    {
        return $this->persistAll($rows);
    }

    public function find(string $id): ?LinkRow
    {
        return $this->findById($id);
    }

    public function remove(string $id): void
    {
        $this->delete($id);
    }
}
