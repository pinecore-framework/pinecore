<?php

namespace Pinecore\Pinecore\Orm;

use PDO;
use PDOStatement;
use Pinecore\Pinecore\Orm\Attributes\ForEntity;
use Pinecore\Pinecore\Orm\Sql\SqlDialect;
use Pinecore\Pinecore\WorkerLifecycle;

abstract class AbstractSqlRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SqlDialect $dialect,
        private readonly SqlHydrator $hydrator,
        private readonly IdGeneratorInterface $ids,
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
        $map     = EntityMap::of($entity::class);
        $idField = $map->idField()->field;
        $row     = $this->hydrator->dehydrate($entity);

        if (EntityMetadata::isNewId($row[$idField] ?? null)) {
            $row[$idField] = $this->ids->generate();
        }

        $sql = $this->dialect->upsert($map->storageName, array_keys($row), $idField);
        $this->execute($sql, $row);

        return $this->hydrator->hydrate($entity::class, $row);
    }

    /**
     * Batched upsert. Rows are written with a multi-row
     * `INSERT … VALUES (…), (…) ON CONFLICT (id) DO UPDATE`, which is an order of
     * magnitude cheaper than one statement per entity for bulk pipelines.
     *
     * Empty ids are generated as in persist(), but — unlike persist() — nothing is
     * hydrated back, so the generated ids are not visible to the caller. Assign
     * them yourself beforehand if you need them.
     *
     * $batchSize is clamped to the dialect's bind-parameter limit divided by the
     * column count. Within a single batch, rows sharing an id are collapsed
     * (last one wins) — Postgres rejects a statement whose ON CONFLICT would
     * touch the same row twice.
     *
     * @param iterable<object> $entities
     * @return int number of rows written
     */
    protected function persistAll(iterable $entities, int $batchSize = 500): int
    {
        $entityClass = $this->entityClass();
        $map         = EntityMap::of($entityClass);
        $idField     = $map->idField()->field;

        $columns = null;
        $limit   = 1;
        $batch   = [];
        $written = 0;

        foreach ($entities as $entity) {
            $row = $this->hydrator->dehydrate($entity);

            if (EntityMetadata::isNewId($row[$idField] ?? null)) {
                $row[$idField] = $this->ids->generate();
            }

            if ($columns === null) {
                $columns = array_keys($row);
                $limit   = $this->batchLimit($batchSize, count($columns));
            }

            // Keyed by id: collapses duplicates inside the batch, last write wins.
            $batch[(string) $row[$idField]] = $row;

            if (count($batch) >= $limit) {
                $written += $this->flushBatch($map->storageName, $columns, $idField, array_values($batch));
                $batch = [];
            }
        }

        if ($batch !== []) {
            /** @var list<string> $columns */
            $written += $this->flushBatch($map->storageName, $columns, $idField, array_values($batch));
        }

        return $written;
    }

    public function findById(string $id): ?object
    {
        $entityClass = $this->entityClass();
        $map         = EntityMap::of($entityClass);
        $idField     = $map->idField()->field;

        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s = :id LIMIT 1',
            $this->selectList($map),
            $this->dialect->quoteIdentifier($map->storageName),
            $this->dialect->quoteIdentifier($idField),
        );

        $row = $this->execute($sql, ['id' => $id])->fetch(PDO::FETCH_ASSOC);

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
            'SELECT %s FROM %s %s LIMIT 1',
            $this->selectList($map),
            $this->dialect->quoteIdentifier($map->storageName),
            $whereSql,
        );

        $row = $this->execute($sql, $params)->fetch(PDO::FETCH_ASSOC);

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

        $this->execute($sql, ['id' => $id]);
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
            'SELECT %s, %s AS distance FROM %s %s ORDER BY distance LIMIT %d',
            $this->selectList($map),
            $distance,
            $this->dialect->quoteIdentifier($map->storageName),
            $whereSql,
            $limit,
        );

        $stmt = $this->execute($sql, $params);

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

    /**
     * Prepares and executes a statement, flagging the worker for recycling if the
     * failure is a lost connection (so the next worker gets a fresh one). The
     * original PDOException is always rethrown for the caller/dispatcher to handle.
     *
     * Use this from subclasses for custom SQL beyond findById/findOneWhere/
     * findNearest — it keeps the connection-loss guard, so a repository never
     * needs its own PDO (which would bypass the recycle behaviour).
     *
     * @param array<string, mixed> $params
     */
    protected function execute(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (\PDOException $e) {
            $this->flagIfConnectionLost($e);
            throw $e;
        }
    }

    /**
     * Runs $fn inside a transaction, keeping the connection-loss guard of
     * execute(): any failure rolls back and rethrows, and a lost connection also
     * flags the worker for recycling.
     *
     * Nested calls **join** the open transaction instead of opening a second one
     * (no SAVEPOINT): only the outermost call issues BEGIN/COMMIT, so a failure
     * anywhere inside rolls the whole unit back. Repositories sharing the PDO
     * singleton therefore compose into one atomic unit.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    final protected function transactional(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }

        try {
            $this->pdo->beginTransaction();
            $result = $fn();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->rollBackQuietly();

            if ($e instanceof \PDOException) {
                $this->flagIfConnectionLost($e);
            }

            throw $e;
        }
    }

    /**
     * Turns a result row into a typed entity. Lets subclasses map the output of
     * arbitrary execute() queries — spatial predicates, joins, aggregates —
     * without reimplementing the mapping or reaching for the hydrator directly.
     *
     * @param array<string, mixed> $row
     * @param ?string $class entity to build; defaults to this repository's #[ForEntity]
     */
    final protected function hydrateRow(array $row, ?string $class = null): object
    {
        return $this->hydrator->hydrate($class ?? $this->entityClass(), $row);
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     * @return list<object>
     */
    final protected function hydrateRows(iterable $rows, ?string $class = null): array
    {
        $class  = $class ?? $this->entityClass();
        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->hydrator->hydrate($class, $row);
        }

        return $result;
    }

    /**
     * Entity → column map, ready to bind in custom SQL (vectors, JSON and
     * date/times already converted).
     *
     * @return array<string, mixed>
     */
    final protected function dehydrateEntity(object $entity): array
    {
        return $this->hydrator->dehydrate($entity);
    }

    /**
     * Normalises a loose value for binding in custom SQL, the same way entity
     * writes and equality filters do. execute() cannot apply this on its own —
     * it would rewrite the parameters of every hand-written query — so reach for
     * it when a bool or a DateTimeInterface goes into $params.
     */
    final protected function bindable(mixed $value): mixed
    {
        return $this->hydrator->toBindable($value);
    }

    /**
     * Explicit SELECT list for the entity's columns, applying #[Field(readExpr:)]
     * / #[Geometry] read expressions and aliasing them back to the column name.
     * Being explicit also decouples hydration from the physical column order and
     * from columns the entity does not declare.
     */
    private function selectList(EntityMetadata $map): string
    {
        $parts = [];
        foreach ($map->sqlFields() as $field) {
            $quoted = $this->dialect->quoteIdentifier($field->field);
            // str_replace, not sprintf: expressions may legitimately contain '%'.
            $parts[] = $field->readExpr === null
                ? $quoted
                : str_replace('%s', $quoted, $field->readExpr) . ' AS ' . $quoted;
        }

        return $parts === [] ? '*' : implode(', ', $parts);
    }

    /**
     * @param list<string>              $columns
     * @param list<array<string, mixed>> $rows
     */
    private function flushBatch(string $table, array $columns, string $idColumn, array $rows): int
    {
        $params = [];
        foreach ($rows as $i => $row) {
            foreach ($columns as $column) {
                $params["r{$i}_{$column}"] = $row[$column] ?? null;
            }
        }

        $sql = $this->dialect->upsertMany($table, $columns, $idColumn, count($rows));

        return $this->execute($sql, $params)->rowCount();
    }

    private function batchLimit(int $batchSize, int $columnCount): int
    {
        $ceiling = intdiv($this->dialect->maxBindParameters(), max(1, $columnCount));

        return max(1, min($batchSize, $ceiling));
    }

    private function rollBackQuietly(): void
    {
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (\PDOException) {
            // The connection is already gone; the original failure is the one
            // worth propagating, so this rollback attempt stays silent.
        }
    }

    private function flagIfConnectionLost(\PDOException $e): void
    {
        if ($this->dialect->isConnectionLost($e)) {
            $this->lifecycle?->requestShutdown();
        }
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
     * Builds the equality filter shared by findOneWhere() and findNearest().
     *
     * `null` becomes `IS NULL` rather than `= :param`: SQL's three-valued logic
     * makes `col = NULL` never true, so binding it would quietly match nothing.
     * Every other value goes through the hydrator's bindable normalisation, so a
     * filter and a write agree on how a value is spelled on the wire.
     *
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
            $quoted = $this->dialect->quoteIdentifier($column);

            if ($value === null) {
                $clauses[] = $quoted . ' IS NULL';
                continue;
            }

            $placeholder          = 'w' . $i++;
            $clauses[]            = $quoted . ' = :' . $placeholder;
            $params[$placeholder] = $this->hydrator->toBindable($value);
        }

        return ['WHERE ' . implode(' AND ', $clauses), $params];
    }
}
