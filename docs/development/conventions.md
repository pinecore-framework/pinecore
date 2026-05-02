# Conventions

## PHP namespaces

| Компонент | Namespace |
|-----------|-----------|
| Core | `Pronchev\Pinecore\` |
| HTTP | `Pronchev\Pinecore\Http\` |
| Auth | `Pronchev\Pinecore\Auth\` |
| ORM | `Pronchev\Pinecore\Orm\` |
| Log | `Pronchev\Pinecore\Log\` |
| Console | `Pronchev\Pinecore\Console\` |
| Model | `Pronchev\Pinecore\Model\` |

PSR-4: файл = имя класса, PascalCase. Атрибуты ORM — PascalCase (`#[Collection]`, `#[Field]`). Интерфейсы — суффикс `Interface`.

## Code style

EditorConfig в корне проекта:

- PHP — UTF-8, LF, 4-space indent, 120-char line limit (PSR-12).
- JS/TS — 2-space indent, 100-char line limit.
- YAML / JSON / Docker / шаблоны — 2-space indent.
