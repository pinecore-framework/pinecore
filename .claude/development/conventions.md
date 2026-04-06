# Conventions

## Commit Messages

```
[type] Краткое описание в imperative form
```

Примеры:
- `Add autoload for routes and services`
- `Fix missing CORS config exception`
- `Refactor WorkerRunner startup sequence`

---

## Branch Naming

| Тип | Паттерн | Пример |
|-----|---------|--------|
| Feature | `feature/description` | `feature/jwt-refresh` |
| Fix | `fix/description` | `fix/cors-header` |
| Refactor | `refactor/description` | `refactor/kernel-bootstrap` |
| Knowledge base / tooling | `kebab-case` | `knowledge-base` |

---

## PHP Namespaces

| Компонент | Namespace |
|-----------|-----------|
| Core | `Pronchev\Pinecore\` |
| HTTP | `Pronchev\Pinecore\Http\` |
| Auth | `Pronchev\Pinecore\Auth\` |
| ORM | `Pronchev\Pinecore\Orm\` |
| Log | `Pronchev\Pinecore\Log\` |
| Console | `Pronchev\Pinecore\Console\` |
| Model | `Pronchev\Pinecore\Model\` |

---

## File & Class Naming

- PSR-4: файл = имя класса, PascalCase (`WorkerRunner.php` содержит `class WorkerRunner`)
- Атрибуты ORM: PascalCase (`#[Collection]`, `#[Field]`)
- Interfaces: суффикс `Interface` (`MiddlewareInterface`)

---

## Работа с задачами (.claude/tasks/)

При старте новой ветки:
```bash
cp .claude/tasks/_template.md .claude/tasks/active/<branch-name>.md
```

Правила:
1. Во время разработки — редактируй только свой task-файл
2. Можно добавить `decisions/ADR-NNN.md` (новый файл — нет конфликтов)
3. Не трогай `architecture/` и `development/` — только в main

При мерже в main:
- Читай секцию "Merge Notes" в task-файле
- Обновляй стабильные docs если нужно
- Перемести: `tasks/active/<branch>.md` → `tasks/completed/<branch>.md`

---

## Когда писать ADR

Создай `decisions/ADR-NNN-title.md` когда:
- Вводится новый архитектурный паттерн
- Принято нетривиальное решение (и кто-то может спросить "почему так?")
- Отвергнута очевидная альтернатива

ADR — только новые файлы, никогда не правь существующие.
