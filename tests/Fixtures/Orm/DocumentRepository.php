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

    /**
     * @param list<float> $vector
     * @return list<array{entity: object, distance: float}>
     */
    public function nearest(array $vector, int $k): array
    {
        return $this->findNearest('embedding', $vector, $k);
    }
}
