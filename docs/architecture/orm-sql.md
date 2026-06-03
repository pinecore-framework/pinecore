# ORM (SQL / PostgreSQL + pgvector)

SQL-ветка ORM, параллельная Mongo-ветке ([`orm.md`](orm.md)). Реализация — **Postgres-first**: CRUD-часть переносима на другие движки через `SqlDialect`, но векторный поиск завязан на **pgvector** (расширение только для PostgreSQL).

> **Зависимости.** SQL-ветка не тянет ни одного composer-пакета — только PHP-расширения `ext-pdo` + `ext-pdo_pgsql`. pgvector доступен через сырой SQL-литерал, отдельной либы не нужно. (В `composer.json` фреймворка они в `suggest`; `ext-pdo_sqlite` — в `require-dev` для CRUD-теста репозитория.)

## Модель хранения (гибридная)

| Атрибут | Колонка в PostgreSQL |
|---|---|
| `#[Id]` | первичный ключ (`text`/`uuid` — `IdGenerator` даёт строку) |
| `#[Field]` | обычная типизированная колонка; имя = свойство или `#[Field(name: 'x')]` |
| `#[Vector(dim: N, metric: 'cosine')]` | `vector(N)` (pgvector); под ANN-индекс |
| `#[Json]` | `jsonb` (значение кодируется/декодируется автоматически) |
| `#[Index]` | B-tree/partial индекс на колонке (раскатывается `pg:ensure-indexes`) |

Скалярные поля и вектор — настоящие колонки (по ним фильтр и индексы). Произвольное тело документа кладётся в `#[Json]` → `jsonb`. `#[Embedded]`/`#[EmbeddedList]` — это Mongo-концепции; в SQL-сущностях используйте `#[Json]`.

## Объявление Entity

```php
#[Table(name: 'documents')]
final class Document implements SqlEntity
{
    public function __construct(
        #[Id]                       public readonly string  $id,
        #[Field(name: 'tenant_id')] public readonly string  $tenantId,
        #[Field]                    public readonly string  $title,
        #[Vector(dim: 1536)]        public readonly array   $embedding,  // float[]
        #[Field]                    public readonly ?string $status   = null,
        #[Json]                     public readonly array   $metadata = [],
    ) {}
}
```

- Сущность реализует `SqlEntity` (маркер) — это переключает `EntityMap` на SQL-ветку и делает `#[Table]` обязательным.
- `#[Id]` пустой (`''`/`null`) при `persist()` → новый id через `IdGenerator`.
- Семантика отсутствующих полей при хидрации — как в Mongo: default → null → `LogicException`.

## Объявление репозитория

```php
#[ForEntity(Document::class)]
final class DocumentRepository extends AbstractSqlRepository
{
    public function save(Document $doc): Document
    {
        return $this->persist($doc);
    }

    /** @return list<array{entity: object, distance: float}> */
    public function searchSimilar(array $vector, int $k = 10, ?string $tenantId = null): array
    {
        return $this->findNearest(
            vectorField: 'embedding',
            vector:      $vector,
            k:           $k,
            where:       $tenantId !== null ? ['tenant_id' => $tenantId] : [],
        );
    }
}
```

## AbstractSqlRepository (`src/Orm/AbstractSqlRepository.php`)

Инжектится через DI: `PDO`, `SqlDialect`, `SqlHydrator`, `IdGeneratorInterface` (дефолт `IdGenerator`; сменная стратегия — [`orm.md`](orm.md#стратегия-генерации-id), напр. `UuidV7Generator` под `uuid`-PK).

| Метод | Описание |
|---|---|
| `persist(object): object` | upsert (`INSERT … ON CONFLICT (id) DO UPDATE`) по полю `id` |
| `findById(string): ?object` | поиск по `id` |
| `findOneWhere(array): ?object` | equality-фильтр (колонка ⇒ значение), ANDed |
| `delete(string): void` | удаление по `id` |
| `findNearest(string $vectorField, array $vector, int $k, array $where = []): list` | kNN-поиск; возвращает `{entity, distance}` (меньше = ближе) |
| `execute(string $sql, array $params = []): PDOStatement` | `protected` — выполнить произвольный SQL с connection-loss guard |
| `entityClass(): string` | читает `#[ForEntity]`, кешируется статически |

`findNearest` берёт метрику из `#[Vector]->metric` соответствующей колонки и подставляет оператор расстояния через диалект — он **обязан совпадать** с opclass ANN-индекса (иначе seq scan). `findOneWhere`/`findNearest` принимают только equality-фильтры; query-builder'а во фреймворке нет.

**Сложные запросы пишутся в собственных методах репозитория через `protected execute()`** — он прогоняет SQL через тот же connection-loss guard (см. ниже), что и встроенные методы. Не заводите в репозитории собственный `PDO`: сырой запрос в обход `execute()` теряет реконнект-резистентность (воркер не ресайклится при обрыве коннекта).

```php
public function deactivateExpired(int $now): int
{
    return $this->execute(
        'UPDATE sessions SET active = false WHERE expires_at < :now',
        ['now' => $now],
    )->rowCount();
}
```

## SqlHydrator (`src/Orm/SqlHydrator.php`)

- `hydrate(string $class, array $row): object` — строка БД → typed entity; парсит vector-литерал `'[..]'` в `float[]` и `json_decode` для `#[Json]`.
- `dehydrate(object $entity): array` — entity → ассоциатив для bind; `float[]` → vector-литерал через `SqlDialect`, `#[Json]` → `json_encode`.

Это единственное содержательное отличие от `MongoHydrator` — остальная логика (обход `fields`, named-args) идентична.

## SqlDialect (`src/Orm/Sql/`)

Изолирует различия движков. Сейчас одна реализация — `PostgresDialect`.

| Метод | Postgres |
|---|---|
| `quoteIdentifier(string)` | `"col"` с экранированием |
| `upsert(table, columns, idColumn)` | `INSERT … ON CONFLICT … DO UPDATE SET col = EXCLUDED.col` |
| `vectorLiteral(array)` | `'[0.1,0.2,…]'` |
| `vectorDistance(column, param, metric)` | `cosine → <=>`, `l2 → <->`, `ip → <#>` |

MySQL-диалект добавляется одним классом для CRUD-части (`upsert` → `ON DUPLICATE KEY UPDATE`, своё quoting). **Векторные методы на MySQL не реализуемы** — pgvector только для PostgreSQL; это сознательная граница.

## Команда `pg:ensure-indexes`

Хендлер: `Pinecore\Pinecore\Orm\Console\PgEnsureIndexesCommand`. Аналог `mongo:ensure-indexes`. Для каждой `SqlEntity` из `config('orm.entities')`:

- по `#[Index]` → `CREATE [UNIQUE] INDEX IF NOT EXISTS … (col [DESC]) [WHERE col IS NOT NULL]` (`sparse` ⇒ partial);
- по `#[Vector]` → `CREATE INDEX IF NOT EXISTS … USING hnsw (col vector_<metric>_ops)`.

Все стейтменты идемпотентны (`IF NOT EXISTS`). **TTL** (`#[Index(expireAfterSeconds:)]`) в SQL не поддержан — команда печатает `[skip]` и пропускает; автоудаление по сроку реализуется отдельной командой по расписанию.

Регистрация в console-конфиге приложения:

```php
new ConsoleDefinition(
    signature:   'pg:ensure-indexes',
    handler:     PgEnsureIndexesCommand::class,
    description: 'Create/update PostgreSQL indexes (B-tree + HNSW) declared via attributes',
    options: [
        new OptionDefinition('dry-run', 'Print plan without changing DB', false),
    ],
)
```

## Wiring (config/services.php)

Соединение и диалект — на стороне приложения (ядро не знает про конкретную БД, как и в случае Mongo):

```php
return function (ContainerBuilder $builder, Config $config): void {
    $builder->addDefinitions([
        PDO::class => fn() => new PDO(
            $config->getString('db.dsn'),       // 'pgsql:host=…;dbname=…'
            $config->getString('db.user'),
            $config->getString('db.password'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        ),
        SqlDialect::class  => fn() => new PostgresDialect(),
    ]);
};
```

> `ATTR_EMULATE_PREPARES => false` — обязательно для корректной типизации параметров в pgsql. Поэтому `LIMIT` во фреймворке инлайнится валидированным `int`, а не биндится плейсхолдером.

## Оборванное соединение (worker recycle)

`PDO` живёт синглтоном весь срок воркера. Если соединение умирает (рестарт PG, idle-timeout, обрыв сети), кешированный коннект мёртв, и без обработки каждый следующий запрос в этом воркере падал бы.

`AbstractSqlRepository` гоняет все операции через единый guard: на `PDOException` спрашивает `SqlDialect::isConnectionLost($e)` (SQLSTATE класса `08` + `57P01/02/03` + фолбэк по сообщению драйвера). Если соединение потеряно — помечает воркер на завершение через `WorkerLifecycle::requestShutdown()` и **пробрасывает исключение дальше** (запрос отдаёт 5xx). После текущей итерации `ProcessLoop` видит флаг и выходит из цикла — рантайм (RoadRunner/FrankenPHP/…) поднимает свежий воркер с новым коннектом. Ошибка БД уровня запроса (например, нарушение уникальности `23505`) воркер **не** ресайклит.

`WorkerLifecycle` инжектится опционально (`?WorkerLifecycle = null`) — в DI это общий синглтон, тот же, что читает `ProcessLoop`; вне воркера (console, тесты) можно не передавать.

## Схема и расширение

DDL пишется вручную (механизма миграций во фреймворке нет; индексы — через `pg:ensure-indexes`):

```sql
CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE documents (
    id         text PRIMARY KEY,
    tenant_id  text NOT NULL,
    title      text NOT NULL,
    status     text,
    embedding  vector(1536),
    metadata   jsonb NOT NULL DEFAULT '{}'
);
```

## Готчи

- **Оператор ⇄ opclass.** `vector_cosine_ops` индекс работает только с `<=>`. Возьмёшь `<->` — индекс молча игнорируется (seq scan). Метрика в `#[Vector]` и opclass в `pg:ensure-indexes` выводятся из одного источника, так что рассинхрон возможен только при ручном DDL.
- **Лимит размерности HNSW.** pgvector индексирует `vector` до 2000 измерений. `text-embedding-3-large` (3072) под индекс требует `halfvec` или усечения размерности.
- **Вектор биндится строкой.** Отдельного PDO-типа нет; `SqlHydrator` гоняет `float[] ↔ '[..]'`.
- **TTL отсутствует.** В Mongo `expireAfterSeconds` чистит документы сам; в SQL это ваша cron-команда.
