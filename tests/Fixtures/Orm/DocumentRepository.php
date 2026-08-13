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

    /** @param iterable<Document> $documents */
    public function saveAll(iterable $documents, int $batchSize = 500): int
    {
        return $this->persistAll($documents, $batchSize);
    }

    /** Custom SQL via the guarded execute() — keeps reconnect-resistance, no own PDO. */
    public function deleteByTenant(string $tenantId): int
    {
        return $this->execute('DELETE FROM documents WHERE tenant_id = :t', ['t' => $tenantId])->rowCount();
    }

    /** Custom SQL mapped back to entities through the protected hydration facade. */
    public function byTenantOrdered(string $tenantId): array
    {
        return $this->hydrateRows(
            $this->execute(
                'SELECT * FROM documents WHERE tenant_id = :t ORDER BY title',
                ['t' => $tenantId],
            )->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    public function firstByTenant(string $tenantId): ?Document
    {
        $row = $this->execute(
            'SELECT * FROM documents WHERE tenant_id = :t ORDER BY title LIMIT 1',
            ['t' => $tenantId],
        )->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrateRow($row);
    }

    /** @return array<string, mixed> */
    public function columnsOf(Document $document): array
    {
        return $this->dehydrateEntity($document);
    }

    /** @template T @param callable():T $fn @return T */
    public function atomically(callable $fn): mixed
    {
        return $this->transactional($fn);
    }

    /** Saves both documents in one unit; the inner call joins the outer transaction. */
    public function savePairAtomically(Document $first, Document $second): void
    {
        $this->transactional(function () use ($first, $second): void {
            $this->persist($first);
            $this->transactional(fn() => $this->persist($second));
        });
    }

    /**
     * @param list<float> $vector
     * @return list<array{entity: object, distance: float}>
     */
    public function nearest(array $vector, int $k): array
    {
        return $this->findNearest('embedding', $vector, $k);
    }

    /**
     * @param list<float>          $vector
     * @param array<string, mixed> $where
     * @return list<array{entity: object, distance: float}>
     */
    public function nearestWhere(array $vector, int $k, array $where): array
    {
        return $this->findNearest('embedding', $vector, $k, $where);
    }

    /** @param array<string, mixed> $where */
    public function firstWhere(array $where): ?Document
    {
        return $this->findOneWhere($where);
    }
}
