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
        private readonly IdGeneratorInterface $ids,
        private readonly WorkerLifecycle $lifecycle,
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

    /**
     * List query over Collection::find(): arbitrary MongoDB filter (as in
     * findOneWhere()), plus `sort`/`limit`/`skip` derived from the arguments.
     * `$filter === []` is legal and means the whole collection.
     *
     * $orderBy keys accept a document key or a property name (resolved through
     * the entity metadata, as #[Field(name:)] may differ from the property);
     * unknown keys and directions other than asc/desc are rejected. Negative
     * $limit/$offset are rejected; `$limit === 0` returns [] without querying
     * (the driver would read `limit: 0` as "no limit").
     *
     * @param array<string, mixed>  $filter
     * @param array<string, string> $orderBy document key/property => 'asc'|'desc'
     * @return list<object>
     */
    protected function findManyWhere(array $filter, array $orderBy = [], ?int $limit = null, ?int $offset = null): array
    {
        if ($limit !== null && $limit < 0) {
            throw new \InvalidArgumentException("findManyWhere(): \$limit must be >= 0, got {$limit}");
        }
        if ($offset !== null && $offset < 0) {
            throw new \InvalidArgumentException("findManyWhere(): \$offset must be >= 0, got {$offset}");
        }

        $entityClass = $this->entityClass();
        $map         = EntityMap::of($entityClass);
        $options     = [];

        $sort = [];
        foreach ($orderBy as $key => $direction) {
            $field = $map->fieldFor((string) $key);
            if ($field === null) {
                throw new \InvalidArgumentException(
                    "findManyWhere(): unknown order-by key '{$key}' — use a document key or property name declared on "
                    . $entityClass
                );
            }
            $sort[$field->field] = match (strtolower((string) $direction)) {
                'asc'   => 1,
                'desc'  => -1,
                default => throw new \InvalidArgumentException(
                    "findManyWhere(): order direction for '{$key}' must be 'asc' or 'desc', got '{$direction}'"
                ),
            };
        }
        if ($sort !== []) {
            $options['sort'] = $sort;
        }
        if ($limit !== null) {
            $options['limit'] = $limit;
        }
        if ($offset !== null) {
            $options['skip'] = $offset;
        }

        if ($limit === 0) {
            return [];
        }

        $cursor = $this->run(fn() => $this->collection($entityClass)->find($filter, $options));

        $result = [];
        foreach ($cursor as $doc) {
            $result[] = $this->hydrator->hydrate($entityClass, $doc);
        }

        return $result;
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
            $this->lifecycle->requestShutdown();
            throw $e;
        }
    }
}
