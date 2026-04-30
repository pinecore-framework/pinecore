# Architecture Decision Records (ADR)

Здесь хранятся записи об архитектурных решениях. Помогают понять **почему** код устроен именно так.

## Индекс

| ADR | Название | Статус |
|-----|----------|--------|
| [001](ADR-001-embedded-orm-symmetry.md) | Symmetric attribute-driven (de)hydration for embedded value objects | Accepted |

## Как писать ADR

Создай новый файл `ADR-NNN-brief-title.md` (NNN = следующий номер) с таким шаблоном:

```markdown
# ADR-NNN: Краткое название

## Status
Accepted

## Context
Какая проблема стояла. Какие альтернативы рассматривались.

## Decision
Что решили.

## Consequences
Что это даёт. Что ограничивает.
```

**Правила**:
- ADR — только **новые файлы**. Существующие ADR не правятся (только Status меняется на "Superseded by ADR-XXX").
- Добавляй ADR прямо из feature-ветки — это не вызывает конфликтов.
- После мержа — обновить этот индекс (строка в таблице).
