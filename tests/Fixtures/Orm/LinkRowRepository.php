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

    /**
     * @param array<string, mixed>  $where
     * @param array<string, string> $orderBy
     * @return list<LinkRow>
     */
    public function listWhere(array $where, array $orderBy = [], ?int $limit = null, ?int $offset = null): array
    {
        return $this->findManyWhere($where, $orderBy, $limit, $offset);
    }

    public function remove(string $id): void
    {
        $this->delete($id);
    }
}
