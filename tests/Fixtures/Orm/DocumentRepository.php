<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Orm\AbstractSqlRepository;
use Pinecore\Pinecore\Orm\Attributes\ForEntity;

#[ForEntity(Document::class)]
final class DocumentRepository extends AbstractSqlRepository
{
    public function save(Document $document): Document
    {
        return $this->persist($document);
    }

    public function find(string $id): ?Document
    {
        return $this->findById($id);
    }

    public function byTitle(string $title): ?Document
    {
        return $this->findOneWhere(['title' => $title]);
    }

    public function remove(string $id): void
    {
        $this->delete($id);
    }

    /** Custom SQL via the guarded execute() — keeps reconnect-resistance, no own PDO. */
    public function deleteByTenant(string $tenantId): int
    {
        return $this->execute('DELETE FROM documents WHERE tenant_id = :t', ['t' => $tenantId])->rowCount();
    }

    /**
     * @param list<float> $vector
     * @return list<array{entity: object, distance: float}>
     */
    public function nearest(array $vector, int $k): array
    {
        return $this->findNearest('embedding', $vector, $k);
    }
}
