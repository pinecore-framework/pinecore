# ORM (MongoDB)

## Атрибуты

| Атрибут | Применяется к | Описание |
|---|---|---|
| `#[Collection(name: 'users')]` | класс entity | Имя коллекции MongoDB |
| `#[Id]` | свойство | Кастомное поле `id` (строка), НЕ MongoDB `_id` |
| `#[Field]` | свойство | Обычное поле; имя в MongoDB = имя свойства |
| `#[Field(name: 'x')]` | свойство | Поле с другим именем в MongoDB |
| `#[Embedded]` | свойство | Вложенный объект (не Entity, просто класс) |
| `#[EmbeddedList]` | свойство | Массив вложенных объектов |
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

Инжектируется через DI: `Database`, `MongoHydrator`, `IdGenerator`.

| Метод | Описание |
|---|---|
| `persist(object $entity): object` | upsert по полю `id`; если `id === ''`, генерирует новый |
| `findById(string $id): ?object` | поиск по полю `id` |
| `findOneWhere(array $filter): ?object` | произвольный MongoDB filter |
| `delete(string $id): void` | deleteOne по полю `id` |
| `collection(string $entityClass): Collection` | MongoDB\Collection для entity |
| `entityClass(): string` | читает `#[ForEntity]`, кешируется статически |

## MongoHydrator (`src/Orm/MongoHydrator.php`)

- `hydrate(string $class, array|BSONDocument $doc): object` — BSONDocument/array → typed entity через named constructor args
- `dehydrate(object $entity): array` — entity → array для MongoDB
- Рекурсивно обрабатывает `#[Embedded]` и `#[EmbeddedList]`
- Embedded при dehydrate: сериализует все свойства ReflectionClass (без атрибутов)

## EntityMap (`src/Orm/EntityMap.php`)

Кеширует метаданные entity (reflection). `EntityMap::of(ClassName::class)` возвращает объект с:
- `collectionName` — из `#[Collection]`
- `fields` — список `FieldMetadata` (property, field name, флаги embedded)
- `idField()` — `FieldMetadata` поля с `#[Id]`
