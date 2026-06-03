# ORM (MongoDB)

> **Зависимость опциональна.** `mongodb/mongodb` вынесена из `require` фреймворка в `suggest`. Приложение, использующее Mongo-ORM, подключает её само: `composer require mongodb/mongodb` (требует `ext-mongodb`). Вся работа с либой заперта в `src/Orm/{AbstractMongoRepository, MongoHydrator, Console/EnsureIndexesCommand}` и резолвится лениво, поэтому без либы ядро (и SQL-ветка) грузится — падение будет только при инстанцировании Mongo-репозитория без установленной либы.

## Атрибуты

| Атрибут | Применяется к | Описание |
|---|---|---|
| `#[Collection(name: 'users')]` | класс entity | Имя коллекции MongoDB |
| `#[Id]` | свойство | Кастомное поле `id` (строка), НЕ MongoDB `_id` |
| `#[Field]` | свойство | Обычное поле; имя в MongoDB = имя свойства |
| `#[Field(name: 'x')]` | свойство | Поле с другим именем в MongoDB |
| `#[Embedded]` | свойство | Вложенный объект (не Entity, просто класс) |
| `#[EmbeddedList]` | свойство | Массив вложенных объектов |
| `#[Index]` | свойство (repeatable) | Объявить индекс на поле; раскатывается командой `mongo:ensure-indexes` |
| `#[ForEntity(Foo::class)]` | класс репозитория | Связывает репозиторий с entity |

## Объявление Entity

```php
#[Collection(name: 'users')]
final class User implements MongoEntity
{
    public function __construct(
        #[Id]    public readonly string  $id,
        #[Field] public readonly string  $email,
        #[Field] public readonly ?string $name = null,
        // массив string[] — BSONArray конвертируется автоматически
        #[Field] public readonly array   $roles = [],
    ) {}
}
```

- `#[Id]` хранится в документе как поле `id` (не `_id`)
- MongoDB `_id` остаётся нативным ObjectId, пинекор его игнорирует
- `array` поля (в т.ч. `string[]`) — `BSONArray` конвертируется в PHP array прозрачно
- Если `$id === ''` при `persist()`, генерируется новый ID через `IdGenerator`

## Объявление репозитория

```php
#[ForEntity(User::class)]
final class UserRepository extends AbstractMongoRepository
{
    // Публичный типизированный метод — не override protect persist()
    public function save(User $user): User
    {
        return $this->persist($user);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->findOneWhere(['email' => $email]);
    }
}
```

## AbstractMongoRepository (`src/Orm/AbstractMongoRepository.php`)

Инжектируется через DI: `Database`, `MongoHydrator`, `IdGeneratorInterface` (дефолт `IdGenerator`), опц. `WorkerLifecycle`.

| Метод | Описание |
|---|---|
| `persist(object $entity): object` | upsert по полю `id`; если `id === ''`, генерирует новый |
| `findById(string $id): ?object` | поиск по полю `id` |
| `findOneWhere(array $filter): ?object` | произвольный MongoDB filter |
| `delete(string $id): void` | deleteOne по полю `id` |
| `collection(string $entityClass): Collection` | MongoDB\Collection для entity |
| `entityClass(): string` | читает `#[ForEntity]`, кешируется статически |

### Оборванное соединение

Операции драйвера обёрнуты в guard: при `MongoDB\Driver\Exception\ConnectionException` репозиторий помечает воркер на завершение через `WorkerLifecycle::requestShutdown()` и пробрасывает исключение. После итерации `ProcessLoop` выходит из цикла — рантайм поднимает новый воркер с живым соединением. Симметрично SQL-ветке (см. [`orm-sql.md`](orm-sql.md#оборванное-соединение-worker-recycle)).

## MongoHydrator (`src/Orm/MongoHydrator.php`)

- `hydrate(string $class, array|BSONDocument $doc): object` — BSONDocument/array → typed entity через named constructor args
- `dehydrate(object $entity): array` — entity → array для MongoDB; рекурсивно вызывает себя на embedded-объектах
- `#[Embedded]` и `#[EmbeddedList]` обрабатываются симметрично через `EntityMap` — при сериализации и десериализации используется одна и та же таблица полей, поэтому `#[Field(name: 'x')]` работает в embedded так же, как в top-level entity.

### Поведение `hydrate` при отсутствии поля в документе

1. Если у конструктора есть default — он применяется (поле не передаётся в named-args).
2. Иначе если свойство nullable — передаётся `null`.
3. Иначе бросается `\LogicException` с именем поля и класса.

Это позволяет безопасно эволюционировать схему (добавлять поля с дефолтами без миграции БД) и даёт понятную ошибку для обязательных пропавших полей.

## EntityMap (`src/Orm/EntityMap.php`)

Кеширует метаданные entity (reflection). `EntityMap::of(ClassName::class)` возвращает объект с:
- `storageName` — имя хранилища: `#[Collection]` для `MongoEntity`, `#[Table]` для `SqlEntity` (см. [`orm-sql.md`](orm-sql.md)); `null` для embedded-классов (без `#[Collection]`/`#[Table]`)
- `fields` — список `FieldMetadata` (property, field name, флаги embedded/json/vector, `isNullable`, `hasConstructorDefault`)
- `idField()` — `FieldMetadata` поля с `#[Id]` (бросает `LogicException`, если нет)

`EntityMap` обслуживает обе ветки ORM: `#[Collection]` обязателен для `MongoEntity`, `#[Table]` — для `SqlEntity`. Embedded value-objects используют те же `#[Field]`/`#[Embedded]`/`#[EmbeddedList]` атрибуты, но ни `MongoEntity`, ни `SqlEntity` не реализуют, и имя хранилища им не нужно (`storageName === null`).

### Валидация атрибутов

`EntityMap::build` валидирует атрибуты при первом обращении к классу (результат кешируется). `\LogicException` бросается **до** хидрации первого документа, с указанием `Класс::$свойство` для быстрой локализации.

**`#[Embedded]`** требует:
- свойство типизировано одним именованным классом (опц. nullable: `?Foo`);
- `mixed`/нетипизированное/примитив (`string`, `int`, ...)/`array`/union/intersection — отвергается;
- класс из типа существует (`class_exists`).

**`#[EmbeddedList(class: Foo::class)]`** требует:
- `Foo` существует;
- свойство типизировано как `array` (опц. `?array`).

**`#[Index]`** требует на том же свойстве `#[Field]` или `#[Id]` — иначе `\LogicException`.

## Индексы

### Атрибут `#[Index]`

Объявляется на свойстве, помеченном `#[Field]` или `#[Id]`. Repeatable — на одном поле допускается несколько индексов с разными опциями.

```php
#[Field] #[Index(unique: true, name: 'uniq_email')]
public readonly string $email;

#[Field(name: 'created_at')] #[Index(direction: -1, expireAfterSeconds: 3600)]
public readonly int $createdAt;

#[Field] #[Index(sparse: true)]
public readonly ?string $referralCode;
```

Параметры `Index`:

| Параметр | По умолчанию | Описание |
|---|---|---|
| `direction` | `1` | `1` asc, `-1` desc |
| `unique` | `false` | Уникальный индекс |
| `sparse` | `false` | Sparse-индекс (исключает документы без поля) |
| `name` | `null` | Кастомное имя; `null` — MongoDB сгенерит |
| `expireAfterSeconds` | `null` | TTL: автоматическое удаление документов |

`#[Index]` на свойстве без `#[Field]`/`#[Id]` (или на `#[Embedded]`/`#[EmbeddedList]`) → `LogicException` при построении метаданных. Имя поля в индексе берётся с учётом `#[Field(name: ...)]` — т.е. в Mongo индекс создаётся на BSON-поле, а не на имени PHP-свойства.

Метаданные доступны как `EntityMap::of(Foo::class)->indexes` — `list<IndexMetadata>` с теми же полями, что у `Index`, плюс `field` (BSON-имя).

### Команда `mongo:ensure-indexes`

Хендлер: `Pinecore\Pinecore\Orm\Console\EnsureIndexesCommand`. Читает список entity из `config/orm.php` (`orm.entities`), для каждого зовёт `Database::selectCollection(...)->createIndex(keys, options)`. `createIndex` идемпотентна — повторный запуск с тем же spec ничего не меняет.

```php
// config/orm.php в приложении
return [
    'entities' => [
        App\Entity\User::class,
        App\Entity\Order::class,
    ],
];
```

Регистрация команды — в console-конфиге приложения:

```php
new ConsoleDefinition(
    signature:   'mongo:ensure-indexes',
    handler:     EnsureIndexesCommand::class,
    description: 'Create/update MongoDB indexes declared via #[Index]',
    options: [
        new OptionDefinition('dry-run', 'Print plan without changing DB', false),
    ],
)
```

Запуск: `php bin/console mongo:ensure-indexes [--dry-run]`. Пустой `orm.entities` → exit code 1 с сообщением в stderr.

Embedded-классы (без `#[Collection]`) пропускаются автоматически. Удаление "лишних" индексов (которые есть в БД, но не в коде) команда **не делает** — только additive. Чистка — отдельная задача (опасно для прода).

## EntityMetadata::isNewId

`AbstractMongoRepository::persist` определяет "новый ли entity" через `EntityMetadata::isNewId($idValue)` — true для `null` и `''`. Это значит, что `#[Id] public readonly ?string $id = null` и `#[Id] public readonly string $id = ''` оба корректно триггерят генерацию нового id через `IdGeneratorInterface`.

## Стратегия генерации ID

Репозиторий (и Mongo, и SQL) инжектит `IdGeneratorInterface { generate(): string }`. `ContainerFactory` биндит дефолт `IdGeneratorInterface::class => fn() => new IdGenerator()`, так что поведение из коробки неизменно. Приложение меняет стратегию одной строкой в `config/services.php`:

```php
use Pinecore\Pinecore\Orm\IdGeneratorInterface;
use Pinecore\Pinecore\Orm\UuidV7Generator;

IdGeneratorInterface::class => fn() => new UuidV7Generator(),
```

| Реализация | Формат |
|---|---|
| `IdGenerator` (дефолт) | `base64url(random_bytes(16))` — 22 символа, URL-safe |
| `UuidV7Generator` | RFC 9562 UUIDv7 — time-ordered, монотонный в пределах процесса, без внешних зависимостей |

`UuidV7Generator` удобен как первичный ключ (лексикографически сортируется хронологически → лучшая index locality). Держите единый инстанс — он хранит счётчик монотонности (DI-бинд это синглтон).
