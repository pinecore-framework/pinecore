<?php

namespace Pinecore\Pinecore\Orm;

use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Exception\ConnectionException;
use Pinecore\Pinecore\Orm\Attributes\ForEntity;
use Pinecore\Pinecore\WorkerLifecycle;

abstract class AbstractMongoRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly MongoHydrator $hydrator,
        private readonly IdGenerator $ids,
        private readonly ?WorkerLifecycle $lifecycle = null,
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

        $this->run(fn() => $this->collection($entity::class)->replaceOne(
            [$idMeta->field => $doc[$idMeta->field]],
            $doc,
            ['upsert' => true],
        ));

        return $this->hydrator->hydrate($entity::class, $doc);
    }

    public function findById(string $id): ?object
    {
        $entityClass = $this->entityClass();
        $idField     = EntityMap::of($entityClass)->idField()->field;
        $doc         = $this->run(fn() => $this->collection($entityClass)->findOne([$idField => $id]));

        return $doc ? $this->hydrator->hydrate($entityClass, $doc) : null;
    }

    protected function findOneWhere(array $filter): ?object
    {
        $entityClass = $this->entityClass();
        $doc         = $this->run(fn() => $this->collection($entityClass)->findOne($filter));

        return $doc ? $this->hydrator->hydrate($entityClass, $doc) : null;
    }

    public function delete(string $id): void
    {
        $entityClass = $this->entityClass();
        $idField     = EntityMap::of($entityClass)->idField()->field;

        $this->run(fn() => $this->collection($entityClass)->deleteOne([$idField => $id]));
    }

    protected function collection(string $entityClass): Collection
    {
        $map = EntityMap::of($entityClass);
        return $this->db->selectCollection($map->storageName);
    }

    /**
     * Runs a driver operation, flagging the worker for recycling if the connection
     * is lost (server restart, network drop) so the next worker reconnects fresh.
     * The original exception is always rethrown.
     */
    private function run(callable $op): mixed
    {
        try {
            return $op();
        } catch (ConnectionException $e) {
            $this->lifecycle?->requestShutdown();
            throw $e;
        }
    }
}
