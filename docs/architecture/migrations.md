# Миграции PostgreSQL (`pg:migrate`)

Механизм raw-SQL миграций для SQL-ветки ORM ([`orm-sql.md`](orm-sql.md)). Осознанно PG-специфичен
(транзакционный DDL, advisory locks) — живёт в `Pg*`-классах и **не** проходит через `SqlDialect`:
тащить эту специфику в общий контракт значило бы навязать будущему MySQL-диалекту нереализуемые методы.

| Файл | Класс | Описание |
|---|---|---|
| `src/Orm/Sql/PgMigration.php` | `PgMigration` | один файл миграции: version, sha256-checksum, SQL, флаг `no-transaction` |
| `src/Orm/Sql/PgMigrationPlan.php` | `PgMigrationPlan` | план: pending / skipped / mismatched / missing |
| `src/Orm/Sql/PgMigrator.php` | `PgMigrator` | логика прогона (advisory lock, транзакции, учёт); тестируется без консоли |
| `src/Orm/Console/PgMigrateCommand.php` | `PgMigrateCommand` | консольная обвязка: конфиг, вывод, exit code |

## Разделение ответственности

**Миграции владеют таблицами, колонками и расширениями. Индексы остаются декларативными** в атрибутах
сущностей (`#[Index]`, `#[Vector]`, `#[Geometry]`, `#[CompositeIndex]`) и раскатываются
`pg:ensure-indexes`. Порядок деплоя:

```bash
bin/console pg:migrate           # сначала схема
bin/console pg:ensure-indexes    # затем индексы по атрибутам
```

## Формат миграций

Каталог задаётся конфигом `orm.migrations_path` (по аналогии с `orm.entities`):

```php
// config/orm.php
return [
    'entities'        => [ /* … */ ],
    'migrations_path' => __DIR__ . '/../migrations',
];
```

- Файлы: `YYYYMMDDHHMMSS_short_name.sql` — сырой SQL, применяется целиком через `PDO::exec()`
  (multi-statement строки допустимы). Порядок применения — **лексикографический** по имени файла.
- Только **up**-миграции. Down нет: откат схемы = новая forward-миграция.
- `.sql`-файл с именем не по шаблону — ошибка (скорее всего опечатка); не-`.sql` файлы игнорируются.
- Параметров/биндинга в миграциях нет.

```sql
-- migrations/20250810120000_create_documents.sql
CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE documents (
    id         text PRIMARY KEY,
    tenant_id  text NOT NULL,
    title      text NOT NULL,
    embedding  vector(1536),
    metadata   jsonb NOT NULL DEFAULT '{}'
);
```

## Учёт применённого

Таблица `schema_migrations` создаётся самой командой идемпотентно (`CREATE TABLE IF NOT EXISTS`):

| Колонка | Тип | Что хранит |
|---|---|---|
| `version` | `text PRIMARY KEY` | имя файла без расширения |
| `checksum` | `text` | sha256 содержимого файла на момент применения |
| `applied_at` | `timestamptz DEFAULT now()` | когда применено |

Поведение при прогоне:

- уже применённые версии **пропускаются**;
- применённый файл изменён (checksum разошёлся) → **ошибка до применения чего-либо**: применённые
  миграции не редактируются, изменение схемы — новая миграция;
- в `schema_migrations` есть версия без файла на диске → **предупреждение**, не ошибка (типично
  при переключении веток).

## Транзакционность и конкурентность

- Каждая миграция применяется **в своей транзакции** вместе со своей строкой в `schema_migrations`
  (PostgreSQL умеет транзакционный DDL). Упавшая миграция откатывается целиком, прогон
  останавливается с exit code ≠ 0; применённые до неё остаются применёнными.
- Директива в **первой строке** файла отключает транзакцию для этой миграции — нужно для
  `CREATE INDEX CONCURRENTLY`, `VACUUM` и прочего, что не работает внутри transaction block:

  ```sql
  -- pinecore: no-transaction
  CREATE INDEX CONCURRENTLY idx_documents_tenant ON documents (tenant_id);
  ```

- На весь прогон берётся **блокирующий `pg_advisory_lock`** с фиксированным ключом
  (`PgMigrator::LOCK_KEY`), освобождается в `finally`: параллельный деплой двух инстансов
  сериализуется, а не применяет миграции наперегонки. Лок сессионный, поэтому лок, миграции
  и анлок идут через один и тот же `PDO` из DI.

## Команда

Регистрация в console-конфиге приложения (фреймворк сам ничего не регистрирует):

```php
new ConsoleDefinition(
    signature:   'pg:migrate',
    handler:     PgMigrateCommand::class,
    description: 'Apply pending SQL migrations from orm.migrations_path',
    options: [
        new OptionDefinition('dry-run', 'Print plan without changing DB', false),
    ],
)
```

- `pg:migrate` — применить все ожидающие; печатает каждую применённую версию и итог
  (`N applied, M skipped`); повторный запуск — no-op. Exit code ≠ 0 при любой ошибке.
- `pg:migrate --dry-run` — план без изменений в БД (не берёт лок и не создаёт
  `schema_migrations`): pending / skipped / расхождения checksum / отсутствующие файлы.
  Расхождение checksum и в dry-run даёт exit code 1. Отдельный `--status` не нужен —
  `--dry-run` его покрывает.

`PgMigrator` автовайрится из DI (нужен только `PDO`, который приложение биндит в
`config/services.php` — см. [«Wiring» в `orm-sql.md`](orm-sql.md#wiring-configservicesphp)).
Composer-зависимостей нет — достаточно `ext-pdo` + `ext-pdo_pgsql`.

## Готчи

- **`no-transaction` ≠ «без транзакции вовсе» при нескольких стейтментах.** `PDO::exec()` шлёт всю
  строку одним батчем (`PQexec`), а PostgreSQL заворачивает multi-statement батч в **неявную
  транзакцию**, даже если явной нет. Поэтому `CREATE INDEX CONCURRENTLY` рядом с любым другим
  стейтментом в том же файле упадёт с `cannot run inside a transaction block`. Файл с директивой
  должен содержать **один стейтмент**; несколько `CONCURRENTLY`-индексов — несколько файлов.
- **Позиция ошибки в multi-statement файле.** Ошибка от PG указывает `LINE N` относительно всего
  батча, а «применилось до ошибки» не остаётся: неявная транзакция откатывает весь `exec()` целиком.
  Атомарность не страдает, но по номеру строки ищите место по всему файлу.
- **Advisory lock и пулеры соединений.** `pg_advisory_lock` — сессионный. PgBouncer в
  transaction/statement pooling выдаёт каждую транзакцию на новом серверном коннекте: лок берётся
  на одном, анлок улетает на другой, а между ними лок вообще ничего не защищает. `pg:migrate`
  должен ходить в PostgreSQL напрямую или через session pooling.
- **`no-transaction` и учёт не атомарны.** Для обычной миграции строка в `schema_migrations`
  коммитится вместе с ней. Для `no-transaction` DDL уже применён к моменту `INSERT`; если запись
  упадёт (обрыв коннекта), миграция применена, но не учтена, и следующий прогон попробует её снова.
  Пишите такие миграции идемпотентно (`CREATE INDEX CONCURRENTLY IF NOT EXISTS …`).
- **Checksum — побайтовый.** Смена перевода строки (LF ↔ CRLF, `git autocrlf`), BOM или хвостовой
  пробел «меняет» уже применённый файл и валит прогон с mismatch. Держите `*.sql` в LF
  (`.editorconfig`/`.gitattributes`) и не переформатируйте применённые миграции.
