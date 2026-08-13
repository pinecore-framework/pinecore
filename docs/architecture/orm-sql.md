# ORM (SQL / PostgreSQL + pgvector)

SQL-ветка ORM, параллельная Mongo-ветке ([`orm.md`](orm.md)). Реализация — **Postgres-first**: CRUD-часть переносима на другие движки через `SqlDialect`, но векторный поиск завязан на **pgvector** (расширение только для PostgreSQL).

> **Зависимости.** SQL-ветка не тянет ни одного composer-пакета — только PHP-расширения `ext-pdo` + `ext-pdo_pgsql`. pgvector доступен через сырой SQL-литерал, отдельной либы не нужно. (В `composer.json` фреймворка они в `suggest`; `ext-pdo_sqlite` — в `require-dev` для CRUD-теста репозитория.)

## Модель хранения (гибридная)

| Атрибут | Колонка в PostgreSQL |
|---|---|
| `#[Id]` | первичный ключ (`text`/`uuid` — `IdGenerator` даёт строку) |
| `#[Field]` | обычная типизированная колонка; имя = свойство или `#[Field(name: 'x')]` |
| `#[Field]` + тип `DateTimeImmutable` | `timestamptz`; hydrator конвертит автоматически (UTC ↔ `DATE_ATOM`) |
| `#[Field(readExpr: 'expr(%s)')]` | колонка, обёрнутая SQL-выражением при чтении (см. [«Выражения на чтение»](#выражения-на-чтение)) |
| `#[Geometry(srid: 4326, type: 'Point')]` | `geometry(...)` (PostGIS): `ST_AsEWKT` при чтении + GiST-индекс |
| `#[Vector(dim: N, metric: 'cosine')]` | `vector(N)` (pgvector); под ANN-индекс |
| `#[Json]` | `jsonb` (значение кодируется/декодируется автоматически) |
| `#[Index]` | B-tree/partial индекс на колонке; `using:` задаёт метод (`gist`, `gin`, …) |
| `#[CompositeIndex]` (на классе) | составной/составной уникальный индекс |

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
| `persistAll(iterable $entities, int $batchSize = 500): int` | пакетный upsert одним multi-row `INSERT`; возвращает число записанных строк |
| `findById(string): ?object` | поиск по `id` |
| `findOneWhere(array): ?object` | equality-фильтр (колонка ⇒ значение), ANDed |
| `delete(string): void` | удаление по `id` |
| `findNearest(string $vectorField, array $vector, int $k, array $where = []): list` | kNN-поиск; возвращает `{entity, distance}` (меньше = ближе) |
| `execute(string $sql, array $params = []): PDOStatement` | выполнить произвольный SQL с connection-loss guard |
| `transactional(callable $fn): mixed` | `final` — обернуть блок в транзакцию, сохранив guard |
| `hydrateRow(array $row, ?string $class = null): object` | `final` — строка результата → сущность |
| `hydrateRows(iterable $rows, ?string $class = null): list` | `final` — набор строк → список сущностей |
| `dehydrateEntity(object): array` | `final` — сущность → колонки, готовые к bind |
| `entityClass(): string` | читает `#[ForEntity]`, кешируется статически |

`findNearest` берёт метрику из `#[Vector]->metric` соответствующей колонки и подставляет оператор расстояния через диалект — он **обязан совпадать** с opclass ANN-индекса (иначе seq scan). `findOneWhere`/`findNearest` принимают только equality-фильтры; query-builder'а во фреймворке нет.

### Свой SQL с маппингом в сущности

**Сложные запросы пишутся в собственных методах репозитория через `protected execute()`** — он прогоняет SQL через тот же connection-loss guard (см. ниже), что и встроенные методы. Не заводите в репозитории собственный `PDO`: сырой запрос в обход `execute()` теряет реконнект-резистентность (воркер не ресайклится при обрыве коннекта).

Результат превращается в типизированные сущности через `hydrateRow()`/`hydrateRows()` — не нужно ни повторять маппинг руками, ни получать наружу сам `SqlHydrator`:

```php
/** @return list<Place> */
public function within(string $wkt, int $limit): array
{
    return $this->hydrateRows(
        $this->execute(
            'SELECT ' /* … */ . ' FROM places WHERE ST_Within(geom, ST_GeomFromEWKT(:area)) LIMIT ' . $limit,
            ['area' => $wkt],
        )->fetchAll(PDO::FETCH_ASSOC),
    );
}
```

Второй параметр `hydrateRow`/`hydrateRows` (`?string $class`) позволяет собрать и **чужую** сущность — полезно, когда join отдаёт строки другой таблицы. По умолчанию берётся класс из `#[ForEntity]`.

`dehydrateEntity()` — обратное направление: сущность → массив колонок (вектор уже литералом, `#[Json]` уже строкой, `DateTimeImmutable` уже в `DATE_ATOM`), пригодный как `$params` для `execute()`.

```php
public function deactivateExpired(int $now): int
{
    return $this->execute(
        'UPDATE sessions SET active = false WHERE expires_at < :now',
        ['now' => $now],
    )->rowCount();
}
```

### Транзакции

```php
public function link(Message $message, Place $place): void
{
    $this->transactional(function () use ($message, $place): void {
        $this->persist($message);
        $this->provenance->record($message, $place);   // другой репозиторий, тот же PDO
    });
}
```

- `commit()` при нормальном возврате, `rollBack()` + проброс исходного исключения при любом падении внутри;
- ошибки соединения идут тем же путём, что в `execute()`: `SqlDialect::isConnectionLost()` → `WorkerLifecycle::requestShutdown()` → проброс;
- если сам `rollBack()` падает (коннект уже мёртв), это **не** маскирует исходное исключение — наружу идёт оно;
- возвращается значение колбэка (`transactional(fn() => $this->persist($e))` вернёт сущность).

**Вложенность: без `SAVEPOINT`.** Если транзакция уже открыта на этом `PDO`, вложенный `transactional()` просто выполняет колбэк, не открывая вторую (`PDO::inTransaction()`). `BEGIN`/`COMMIT` выдаёт только самый внешний вызов, поэтому падение на любой глубине откатывает **всю** единицу работы. Репозитории делят `PDO`-синглтон, так что несколько репозиториев в одной транзакции складываются в один атомарный блок автоматически. Частичного отката (поймать исключение внутри и продолжить транзакцию) нет — при необходимости это отдельная фича поверх `SAVEPOINT`.

### Пакетная запись

`persistAll()` пишет одним `INSERT … VALUES (…), (…), … ON CONFLICT (id) DO UPDATE` вместо стейтмента на сущность — для пайплайнов, льющих десятки тысяч строк за проход.

```php
$written = $this->persistAll($mentions);            // дефолт 500 строк в пачке
$written = $this->persistAll($mentions, 1000);      // или явно
```

- принимает любой `iterable`, включая генератор — вход не материализуется целиком;
- пустой `id` заполняется `IdGenerator`, как в `persist()`; но **обратно ничего не гидрируется**, поэтому сгенерированные id вызывающему не видны. Нужны id — проставьте их сами до вызова (или пишите через `persist()`);
- `$batchSize` зажимается сверху: `SqlDialect::maxBindParameters()` / число колонок (у Postgres — 65535 плейсхолдеров на стейтмент). Передать `PHP_INT_MAX` безопасно;
- **дубликаты `id` внутри одной пачки схлопываются**, побеждает последний: Postgres отвергает стейтмент, в котором `ON CONFLICT` задел бы одну строку дважды (`command cannot affect row a second time`). В разных пачках дубликаты отрабатывают обычным upsert'ом;
- возвращает суммарный `rowCount()` по всем стейтментам.

## Выражения на чтение

Чтение идёт **явным списком колонок**, а не `SELECT *` — это развязывает гидрацию с физическим порядком колонок в таблице и даёт вклиниться выражению:

```php
#[Field(readExpr: 'ST_AsEWKT(%s)')] public readonly ?string $geom;
// SELECT "id", …, ST_AsEWKT("geom") AS "geom" FROM …
```

- `%s` — плейсхолдер уже квотированного имени колонки; подстановка делается `str_replace`, **не** `sprintf`, поэтому литеральный `%` внутри выражения безопасен;
- результат всегда алиасится обратно в имя колонки, так что гидратор ничего не замечает;
- без `readExpr` поведение прежнее — колонка выбирается как есть;
- фильтры (`findOneWhere`, `where` в `findNearest`) бьются по **сырой** колонке, а не по выражению — оборачивается только проекция.

Симметричного `writeExpr` нет: для геометрии он не нужен (см. ниже), а вводить его значит тащить выражения в `upsert`/`upsertMany` и рассинхронизировать сущность, которую возвращает `persist()`, с тем, что реально легло в БД.

### Геометрия (PostGIS)

`#[Geometry]` — сокращение для «поле + `readExpr` + GiST-индекс»:

```php
#[Table(name: 'places')]
final class Place implements SqlEntity
{
    public function __construct(
        #[Id]                                   public readonly string  $id,
        #[Geometry(srid: 4326, type: 'Point')]  public readonly ?string $geom = null,
    ) {}
}
```

| Параметр | Значение |
|---|---|
| `name` | имя колонки (по умолчанию — имя свойства) |
| `srid`, `type` | декларативные метаданные для приложения; на генерируемый SQL не влияют (DDL — вне фреймворка) |
| `index` | `true` (дефолт) ⇒ `pg:ensure-indexes` создаёт GiST; поставьте `false`, если объявляете индекс сами |
| `readExpr` | по умолчанию `ST_AsEWKT(%s)`; замените на `ST_AsGeoJSON(%s)` и т.п. при необходимости |

**Запись выражения не требует.** EWKT-строка (`SRID=4326;POINT(37.6 55.7)`) биндится обычным параметром — PostgreSQL сам применяет входную функцию типа колонки, так что `persist()` кладёт и точку, и полигон без доработок. Ломается только **чтение**: без `readExpr` в `string`-свойство приезжает HEXEWKB (`0101000020E6100000…`), а не WKT.

## SqlHydrator (`src/Orm/SqlHydrator.php`)

- `hydrate(string $class, array $row): object` — строка БД → typed entity; парсит vector-литерал `'[..]'` в `float[]`, `json_decode` для `#[Json]`, оборачивает `timestamptz` в `DateTimeImmutable` и **приводит скалярные колонки к объявленному типу** (см. ниже).
- `dehydrate(object $entity): array` — entity → ассоциатив для bind; `float[]` → vector-литерал через `SqlDialect`, `#[Json]` → `json_encode`, `DateTimeInterface` → строка.

### Скалярные типы (приведение при чтении)

Драйвер `pdo_pgsql` с `ATTR_EMULATE_PREPARES=false` возвращает **все** колонки строками, а `boolean` — как `'t'`/`'f'`. Без приведения свойство, типизированное `bool`, получило бы строку `'f'` → в coercive-режиме это непустая строка ≠ `'0'` → коэрсится в `true` (тихий баг видимости/флагов), а под `strict_types` — `TypeError`.

`hydrate` кастует значение по объявленному типу свойства (`FieldMetadata->type`, с `ltrim('?')`):

- `bool` ← `'t'/'f'/'1'/'0'/'true'/'false'/'y'/'yes'`, а также нативные `bool`/`int` → реальный `bool`;
- `int` → `(int)`, `float` → `(float)`;
- `string`, `mixed`, union-типы и классовые типы — **без изменений** (обратная совместимость).

`null` остаётся `null`. Приведение идемпотентно: драйвер, отдающий уже нативные `bool`/`int`, тоже обрабатывается корректно.

Это единственное содержательное отличие от `MongoHydrator` — остальная логика (обход `fields`, named-args) идентична.

### Дата/время

Конверсия **type-driven и opt-in**: свойство `#[Field]`, типизированное `DateTimeImmutable` (или `?DateTimeImmutable`), автоматически маппится на `timestamptz`. Поля типа `string` не затрагиваются — обратная совместимость.

- **dehydrate** (value-driven): любое значение `instanceof DateTimeInterface` → нормализуется в **UTC** и форматируется в `DATE_ATOM` (`2026-06-03T12:00:00+00:00`). Исходный объект не мутируется (`DateTimeImmutable::createFromInterface`).
- **hydrate**: строка из колонки → `new DateTimeImmutable(...)`, приведённый к **UTC**.

Оба конца — UTC. Колонку объявляйте `timestamptz` (хранит момент времени), не `timestamp`.

## SqlDialect (`src/Orm/Sql/`)

Изолирует различия движков. Сейчас одна реализация — `PostgresDialect`.

| Метод | Postgres |
|---|---|
| `quoteIdentifier(string)` | `"col"` с экранированием |
| `upsert(table, columns, idColumn)` | `INSERT … ON CONFLICT … DO UPDATE SET col = EXCLUDED.col` |
| `upsertMany(table, columns, idColumn, rowCount)` | то же на `rowCount` строк; плейсхолдеры `:r{i}_{col}` |
| `maxBindParameters()` | `65535` — потолок пачки в `persistAll()` |
| `vectorLiteral(array)` | `'[0.1,0.2,…]'` |
| `vectorDistance(column, param, metric)` | `cosine → <=>`, `l2 → <->`, `ip → <#>` |

MySQL-диалект добавляется одним классом для CRUD-части (`upsert` → `ON DUPLICATE KEY UPDATE`, своё quoting). **Векторные методы на MySQL не реализуемы** — pgvector только для PostgreSQL; это сознательная граница.

## Команда `pg:ensure-indexes`

Хендлер: `Pinecore\Pinecore\Orm\Console\PgEnsureIndexesCommand`. Аналог `mongo:ensure-indexes`. Для каждой `SqlEntity` из `config('orm.entities')`:

- по `#[Index]` → `CREATE [UNIQUE] INDEX IF NOT EXISTS … [USING method] (col [DESC]) [WHERE col IS NOT NULL]` (`sparse` ⇒ partial);
- по `#[CompositeIndex]` (атрибут класса) → составной индекс на нескольких колонках;
- по `#[Vector]` → `CREATE INDEX IF NOT EXISTS … USING hnsw (col vector_<metric>_ops)`;
- по `#[Geometry]` → `CREATE INDEX IF NOT EXISTS … USING gist (col)` (если не `index: false`).

Все стейтменты идемпотентны (`IF NOT EXISTS`), повторный запуск ничего не меняет. **TTL** (`#[Index(expireAfterSeconds:)]`) в SQL не поддержан — команда печатает `[skip]` и пропускает; автоудаление по сроку реализуется отдельной командой по расписанию.

### Метод индекса и составные индексы

```php
#[Table(name: 'place_external_ref')]
#[CompositeIndex(columns: ['source', 'externalId'], unique: true)]
#[CompositeIndex(columns: ['context_hash', 'place_id'], name: 'idx_ctx')]
final class PlaceExternalRef implements SqlEntity
{
    public function __construct(
        #[Id]                       public readonly string $id,
        #[Field]                    public readonly string $source,
        #[Field(name: 'external_id')] public readonly string $externalId,
        #[Field] #[Index(using: 'gin')] public readonly ?string $payload = null,
    ) {}
}
```

- элементы `columns` — либо **имена свойств** (резолвятся в свои колонки: `externalId` → `external_id`), либо **сырые имена колонок** (проходят как есть); это позволяет индексировать и то, что сущность не объявляет;
- `using` — из белого списка PostgreSQL (`btree`, `hash`, `gist`, `spgist`, `gin`, `brin`); опечатка даёт `InvalidArgumentException`, а не синтаксическую ошибку в БД. Значение подставляется в DDL без кавычек, поэтому список закрытый;
- `direction: -1` печатается как `DESC` только для B-tree — у gist/gin упорядоченной формы нет;
- имя по умолчанию: `idx_<table>_<col1>_<col2>…`. Длинные имена PostgreSQL обрежет до 63 байт — детерминированно, идемпотентность не страдает, но при коллизии задайте `name:` явно;
- `using` на Mongo-ветке игнорируется, `#[CompositeIndex]` там не читается вовсе.

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

### Readiness-проба (`SqlHealthCheck`)

Guard выше срабатывает **только** на запросах через `AbstractSqlRepository`. Readiness-контроллер, который пингует БД напрямую (`$pdo->query('SELECT 1')`), обходит guard: при `worker.max_requests=0` (без рециклинга по счётчику) воркер держит протухший `PDO` после рестарта Postgres, и `/ready` отдаёт 503 бесконечно, пока воркер не перезапустят вручную.

`SqlHealthCheck` (`src/Orm/Sql/SqlHealthCheck.php`) закрывает дыру — пинг через тот же путь рецикла:

| Метод | Поведение |
|---|---|
| `isHealthy(): bool` | `SELECT 1`; `true` при успехе. На любой ошибке — `false` (проба = «не готов»); если ошибка — потеря соединения (`SqlDialect::isConnectionLost`), дополнительно помечает воркер на рецикл через тот же `WorkerLifecycle`. |

Так после рестарта Postgres первая же проба `/ready` → 503 **и** флаг рецикла → `ProcessLoop` выходит из цикла (даже при `max_requests=0`) → рантайм поднимает свежий воркер с живым коннектом → `/ready` сам зеленеет, без ручного рестарта.

Контроллер на стороне приложения:

```php
final class ReadinessController
{
    public function __construct(private readonly SqlHealthCheck $health) {}

    public function __invoke(): Response
    {
        return $this->health->isHealthy()
            ? Response::json(['status' => 'ready'])
            : Response::json(['status' => 'unavailable'], 503);
    }
}
```

`SqlHealthCheck` автовайрится из DI (`PDO` + `SqlDialect` + общий `WorkerLifecycle`-синглтон). Зависит только от PDO-пинга — то же `ext-pdo`, никаких новых зависимостей.

## Схема и расширение

DDL пишется вручную (механизма миграций во фреймворке нет; индексы — через `pg:ensure-indexes`):

```sql
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS postgis;   -- если используете #[Geometry]

CREATE TABLE documents (
    id         text PRIMARY KEY,
    tenant_id  text NOT NULL,
    title      text NOT NULL,
    status     text,
    embedding  vector(1536),
    geom       geometry(Point, 4326),
    metadata   jsonb NOT NULL DEFAULT '{}'
);
```

## Готчи

- **Оператор ⇄ opclass.** `vector_cosine_ops` индекс работает только с `<=>`. Возьмёшь `<->` — индекс молча игнорируется (seq scan). Метрика в `#[Vector]` и opclass в `pg:ensure-indexes` выводятся из одного источника, так что рассинхрон возможен только при ручном DDL.
- **Лимит размерности HNSW.** pgvector индексирует `vector` до 2000 измерений. `text-embedding-3-large` (3072) под индекс требует `halfvec` или усечения размерности.
- **Вектор биндится строкой.** Отдельного PDO-типа нет; `SqlHydrator` гоняет `float[] ↔ '[..]'`.
- **`pdo_pgsql` отдаёт всё строками.** При `ATTR_EMULATE_PREPARES=false` числа приходят строками, а `bool` — как `'t'`/`'f'`. `SqlHydrator` приводит скаляры по объявленному типу свойства; не объявляйте флаг как `string` (получите `'t'`/`'f'` вместо `bool`) — типизируйте `bool`/`int`/`float`.
- **Readiness в обход guard.** `/ready`, который пингует `PDO` напрямую, не ресайклит воркер при обрыве коннекта (вечный 503 при `max_requests=0`). Пингуйте через `SqlHealthCheck::isHealthy()`.
- **TTL отсутствует.** В Mongo `expireAfterSeconds` чистит документы сам; в SQL это ваша cron-команда.
- **Геометрия: пишется как есть, читается — нет.** EWKT-строка биндится обычным параметром, `persist()` работает без доработок. А вот на чтении PostGIS отдаёт **HEXEWKB** (`0101000020E6100000…`), и в `string`-свойство приезжает hex вместо WKT. Лечится `#[Geometry]` (или `#[Field(readExpr: 'ST_AsEWKT(%s)')]`) — иначе получите молча «испорченные» координаты.
- **Явный список колонок сменил режим отказа.** Раньше чтение шло `SELECT *`, и поле сущности, под которое ещё нет колонки, молча подхватывало дефолт конструктора. Теперь такая колонка попадает в `SELECT` и запрос падает с `column … does not exist`. Это заметнее и правильнее, но если вы добавляли поля в сущность до миграции — сначала миграция.
- **`readExpr` — это сырой SQL.** Выражение подставляется в `SELECT` без экранирования (квотируется только имя колонки). Оно приходит из атрибута в вашем коде, не из ввода, но не собирайте его динамически из внешних данных.
- **Дубли `id` в одной пачке.** `INSERT … ON CONFLICT DO UPDATE` не может задеть одну строку дважды — Postgres валит весь стейтмент. `persistAll()` схлопывает дубли внутри пачки (побеждает последний); если вам нужна другая семантика — дедуплицируйте на входе.
- **`persistAll()` не возвращает сущности.** Сгенерированные `id` наружу не отдаются (в отличие от `persist()`). Нужны — проставляйте до вызова.
- **Вложенная транзакция не изолирована.** `transactional()` внутри `transactional()` не открывает `SAVEPOINT`: исключение во внутреннем блоке откатывает и внешний. Поймать ошибку внутри и продолжить транзакцию нельзя.
- **`--dry-run` не проверяет схему.** `pg:ensure-indexes --dry-run` печатает DDL, не заглядывая в БД: индекс на несуществующую колонку (в т.ч. заданную сырым именем в `#[CompositeIndex]`) всплывёт только на реальном прогоне.
