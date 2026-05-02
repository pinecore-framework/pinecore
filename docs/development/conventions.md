# Conventions

## PHP namespaces

| Компонент | Namespace |
|-----------|-----------|
| Core | `Pinecore\Pinecore\` |
| HTTP | `Pinecore\Pinecore\Http\` |
| Auth | `Pinecore\Pinecore\Auth\` |
| ORM | `Pinecore\Pinecore\Orm\` |
| Log | `Pinecore\Pinecore\Log\` |
| Console | `Pinecore\Pinecore\Console\` |
| Model | `Pinecore\Pinecore\Model\` |

PSR-4: файл = имя класса, PascalCase. Атрибуты ORM — PascalCase (`#[Collection]`, `#[Field]`). Интерфейсы — суффикс `Interface`.

## Code style

EditorConfig в корне проекта:

- PHP — UTF-8, LF, 4-space indent, 120-char line limit (PSR-12).
- JS/TS — 2-space indent, 100-char line limit.
- YAML / JSON / Docker / шаблоны — 2-space indent.
