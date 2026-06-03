<?php

namespace Pinecore\Pinecore\Orm;

use PDO;
use Pinecore\Pinecore\Orm\Attributes\ForEntity;
use Pinecore\Pinecore\Orm\Sql\SqlDialect;

abstract class AbstractSqlRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SqlDialect $dialect,
        private readonly SqlHydrator $hydrator,
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
        $map     = EntityMap::of($entity::class);
        $idField = $map->idField()->field;
        $row     = $this->hydrator->dehydrate($entity);

        if (EntityMetadata::isNewId($row[$idField] ?? null)) {
            $row[$idField] = $this->ids->generate();
        }

        $sql = $this->dialect->upsert($map->storageName, array_keys($row), $idField);
        $this->pdo->prepare($sql)->execute($row);

        return $this->hydrator->hydrate($entity::class, $row);
    }

    public function findById(string $id): ?object
    {
        $entityClass = $this->entityClass();
        $map         = EntityMap::of($entityClass);
        $idField     = $map->idField()->field;

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = :id LIMIT 1',
            $this->dialect->quoteIdentifier($map->storageName),
            $this->dialect->quoteIdentifier($idField),
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrator->hydrate($entityClass, $row) : null;
    }

    /**
     * @param array<string, mixed> $where equality clauses ANDed together
     */
    protected function findOneWhere(array $where): ?object
    {
        $entityClass         = $this->entityClass();
        $map                 = EntityMap::of($entityClass);
        [$whereSql, $params] = $this->buildWhere($where);

        $sql = sprintf(
            'SELECT * FROM %s %s LIMIT 1',
            $this->dialect->quoteIdentifier($map->storageName),
            $whereSql,
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrator->hydrate($entityClass, $row) : null;
    }

    public function delete(string $id): void
    {
        $entityClass = $this->entityClass();
        $map         = EntityMap::of($entityClass);
        $idField     = $map->idField()->field;

        $sql = sprintf(
            'DELETE FROM %s WHERE %s = :id',
            $this->dialect->quoteIdentifier($map->storageName),
            $this->dialect->quoteIdentifier($idField),
        );

        $this->pdo->prepare($sql)->execute(['id' => $id]);
    }

    /**
     * k-nearest-neighbour search against a #[Vector] column. Returns the matched
     * entities with their raw distance (lower = closer); the metric/operator is
     * taken from the column's #[Vector] declaration so it matches the ANN index.
     *
     * @param list<float>          $vector query embedding
     * @param array<string, mixed> $where  optional equality pre-filter
     * @return list<array{entity: object, distance: float}>
     */
    protected function findNearest(string $vectorField, array $vector, int $k, array $where = []): array
    {
        $entityClass = $this->entityClass();
        $map         = EntityMap::of($entityClass);
        $metric      = $this->vectorMetric($map, $vectorField);

        [$whereSql, $params] = $this->buildWhere($where);
        $params['vec'] = $this->dialect->vectorLiteral($vector);

        $distance = $this->dialect->vectorDistance($vectorField, ':vec', $metric);
        $limit    = max(1, $k);

        $sql = sprintf(
            'SELECT *, %s AS distance FROM %s %s ORDER BY distance LIMIT %d',
            $distance,
            $this->dialect->quoteIdentifier($map->storageName),
            $whereSql,
            $limit,
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dist = (float) $row['distance'];
            unset($row['distance']);
            $results[] = [
                'entity'   => $this->hydrator->hydrate($entityClass, $row),
                'distance' => $dist,
            ];
        }

        return $results;
    }

    private function vectorMetric(EntityMetadata $map, string $vectorField): string
    {
        foreach ($map->fields as $field) {
            if ($field->isVector && $field->field === $vectorField) {
                return $field->vectorMetric;
            }
        }

        throw new \LogicException(
            "No #[Vector] column '{$vectorField}' declared on the entity for " . static::class
        );
    }

    /**
     * @param array<string, mixed> $where
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $where): array
    {
        if ($where === []) {
            return ['', []];
        }

        $clauses = [];
        $params  = [];
        $i       = 0;
        foreach ($where as $column => $value) {
            $placeholder           = 'w' . $i++;
            $clauses[]             = $this->dialect->quoteIdentifier($column) . ' = :' . $placeholder;
            $params[$placeholder]  = $value;
        }

        return ['WHERE ' . implode(' AND ', $clauses), $params];
    }
}
