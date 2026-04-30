# ADR-001: Symmetric attribute-driven (de)hydration for embedded value objects

## Status
Accepted

## Context

`MongoHydrator::dehydrate` для верхнеуровневой entity ходил через `EntityMap` и
учитывал `#[Field(name: 'x')]`, а `dehydrateEmbedded` для вложенных объектов
писал ключи напрямую через `ReflectionClass::getProperties()` — без учёта
атрибутов. В то же время `hydrate` всегда читал ключи из метаданных. Это давало
несимметрию: после `dehydrate` → `hydrate` round-trip ломался, если у embedded
свойства был `#[Field(name: 'x')]` или свойство-без-атрибутов (последнее
сохранялось, но не считывалось).

Дополнительно: `EntityMap::of()` требовал `#[Collection]` на любом классе, для
которого строились метаданные — то есть embedded-классы тоже должны были его
ставить, что бессмысленно.

Альтернативы:

1. **Оставить два пути и продублировать в `dehydrateEmbedded` обход через
   `EntityMap`**. Минус: код в двух местах эволюционирует параллельно, легко
   разойтись снова.
2. **Сделать `#[Collection]` опциональным в `EntityMap` и слить
   `dehydrateEmbedded` в `dehydrate` через рекурсию**. Один источник истины.
3. Завести отдельный `EmbeddedMap` параллельно `EntityMap`. Дублирование
   reflection-логики, тот же набор атрибутов.

## Decision

Принят вариант 2.

- `#[Collection]` обязателен только для классов, реализующих `MongoEntity`.
  Embedded value-objects этот интерфейс не реализуют, `EntityMap` строит для
  них метаданные с `collectionName === null`.
- `MongoHydrator::dehydrate` рекурсивно вызывает себя на embedded-значениях и
  на элементах embedded-list — `#[Field(name: ...)]` теперь honoured
  симметрично с `hydrate`.

## Consequences

**Плюсы:**
- Round-trip embedded полей работает корректно при использовании `#[Field(name: ...)]`.
- Один путь сериализации — добавление новой логики (например, конверсии
  типов) автоматически работает и для top-level, и для embedded.

**Минусы / breaking change:**
- Embedded-классы должны явно объявлять `#[Field]` на свойствах. Раньше
  `dehydrateEmbedded` писал даже свойства без атрибутов — теперь они
  пропускаются.
  Поскольку прежнее поведение всё равно было сломано на стороне `hydrate`
  (он эти свойства не читал), на практике никакой работающий код не зависел
  от старого поведения.

**Связанные тесты:** `tests/Orm/MongoHydratorTest.php` —
`embeddedRoundTripUsesRenamedFieldKey`, `embeddedListRoundTrip`,
`hydrateAcceptsBSONArrayForEmbeddedList`.
