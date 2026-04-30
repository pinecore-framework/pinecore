<?php

namespace Pronchev\Pinecore\Orm;

use MongoDB\Collection;
use MongoDB\Database;
use Pronchev\Pinecore\Orm\Attributes\ForEntity;

abstract class AbstractMongoRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly MongoHydrator $hydrator,
        private readonly IdGenerator $ids,
    ) {}

    /**
     * Resolves the entity class for this repository from the #[ForEntity] attribute.
     * Result is cached for the worker lifetime.
     */
    final protected function entityClass(): string
    {
        static $cache = [];

        return $cache[static::class] ??= (function (): string {
            $attrs = (new \ReflectionClass(static::class))->getAttributes(ForEntity::class);

            if (empty($attrs)) {
                throw new \LogicException(
                    static::class . ' must declare #[ForEntity(EntityClass::class)]'
                );
            }

            return $attrs[0]->newInstance()->class;
        })();
    }

    protected function persist(object $entity): object
    {
        $map    = EntityMap::of($entity::class);
        $idMeta = $map->idField();
        $doc    = $this->hydrator->dehydrate($entity);

        if (EntityMetadata::isNewId($doc[$idMeta->field] ?? null)) {
            $doc[$idMeta->field] = $this->ids->generate();
        }

        $this->collection($entity::class)->replaceOne(
            [$idMeta->field => $doc[$idMeta->field]],
            $doc,
            ['upsert' => true],
        );

        return $this->hydrator->hydrate($entity::class, $doc);
    }

    public function findById(string $id): ?object
    {
        $entityClass = $this->entityClass();
        $idField     = EntityMap::of($entityClass)->idField()->field;
        $doc         = $this->collection($entityClass)->findOne([$idField => $id]);

        return $doc ? $this->hydrator->hydrate($entityClass, $doc) : null;
    }

    protected function findOneWhere(array $filter): ?object
    {
        $entityClass = $this->entityClass();
        $doc         = $this->collection($entityClass)->findOne($filter);

        return $doc ? $this->hydrator->hydrate($entityClass, $doc) : null;
    }

    public function delete(string $id): void
    {
        $entityClass = $this->entityClass();
        $idField     = EntityMap::of($entityClass)->idField()->field;

        $this->collection($entityClass)->deleteOne([$idField => $id]);
    }

    protected function collection(string $entityClass): Collection
    {
        $map = EntityMap::of($entityClass);
        return $this->db->selectCollection($map->collectionName);
    }
}
