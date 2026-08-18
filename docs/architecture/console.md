# Console

## Компоненты

| Файл | Класс | Описание |
|---|---|---|
| `src/Console/ConsoleApplication.php` | `ConsoleApplication` | Точка входа: парсит argv, матчит команду, запускает handler |
| `src/Console/ConsoleRouter.php` | `ConsoleRouter` | Аналог HTTP Router для консольных команд |
| `src/Console/ConsoleDefinition.php` | `ConsoleDefinition` | signature, handler class, description, options[] |
| `src/Console/OptionDefinition.php` | `OptionDefinition` | name, description, default (false = флаг) |
| `src/Console/ConsoleInput.php` | `ConsoleInput` | Распарсенные path params + options |
| `src/Console/ConsoleOutput.php` | `ConsoleOutput` | Тонкая обёртка над `League\CLImate` (цвета, прогресс-бары, таблицы), exit code |
| `src/Console/ConsoleMatch.php` | `ConsoleMatch` | Результат матчинга (аналог RouteMatch) |

Зависимость: [`league/climate`](https://climate.thephpleague.com/) — даёт ANSI-цвета, прогресс-бары и таблицы.
Сам `ConsoleApplication` тоже принимает `CLImate` (default — `new CLImate()`), чтобы help рисовался цветным
и чтобы тесты могли подменить writer на `Buffer`.

## ConsoleDefinition

```php
new ConsoleDefinition(
    signature:   'users:create {role}',    // {param} — path param из сигнатуры
    handler:     CreateUserCommand::class,
    description: 'Create a new user',
    options: [
        new OptionDefinition('dry-run', 'Do not persist', false),   // флаг (default=false)
        new OptionDefinition('email',   'User email',     null),     // опция (default=null)
    ],
)
```

## Handler

Invokable-класс, резолвится через DI:
```php
final class CreateUserCommand
{
    public function __invoke(ConsoleInput $input, ConsoleOutput $output): void
    {
        $role   = $input->pathParams['role'];
        $dryRun = $input->option('dry-run');      // bool для флагов
        $email  = $input->option('email');         // string|null для опций

        $output->success("created {$role}");
        $output->setExitCode(0);
    }
}
```

## ConsoleOutput

Семантические методы пишут с готовой раскраской:
- `success(string)` — зелёная галочка `✓`
- `info(string)` — green
- `comment(string)` — yellow (для dry-run/диагностики)
- `warn(string)` — yellow с `⚠`
- `error(string)` — light_red, в STDERR
- `writeln(string)` / `write(string)` — без цвета; внутри строки можно использовать
  CLImate-теги: `<info>`, `<comment>`, `<error>`, `<bold>`, `<dim>`, `<cyan>` и т. д.

Для дольгих операций — прогресс-бары и таблицы:
```php
$bar = $output->progress(count($users));
foreach ($users as $u) {
    $bar->advance();
    // ...
}

$output->table([
    ['id' => 1, 'email' => 'a@x'],
    ['id' => 2, 'email' => 'b@x'],
]);
```

Низкоуровневый `CLImate` доступен как `$output->climate` (например, для `animation()` или `confirm()`).

## Запуск

```php
// bin/console
$container = Kernel::boot(__DIR__ . '/..');
$app       = $container->get(ConsoleApplication::class);
exit($app->run($argv));
```

Команды: `bin/console <signature> [--option=value] [--flag]`
Help:
- `bin/console help` — общий хелп с группировкой команд по неймспейсу (префикс до `:`).
- `bin/console help <command>` — подробный хелп: usage, аргументы, опции с дефолтами.
- `bin/console help <namespace>` — список команд только из этого неймспейса.

## Регистрация команд

Конвенциональный способ — `config/commands.php` (по аналогии с `config/routes.php`):

```php
use Pinecore\Pinecore\Console\ConsoleDefinition;
use Pinecore\Pinecore\Console\OptionDefinition;

return [
    new ConsoleDefinition('mongo:ensure-indexes', EnsureIndexesCommand::class, 'Ensure MongoDB indexes'),
    new ConsoleDefinition('users:create {role}', CreateUserCommand::class, 'Create a new user', [
        new OptionDefinition('email', 'User email', null),
        new OptionDefinition('dry-run', 'Do not persist', false),
    ]),
];
```

`ContainerFactory` сам соберёт `ConsoleRouter` из этого списка. Если файла нет —
`ConsoleRouter` резолвится пустым, и `bin/console help` показывает «No commands registered»
(удобно для скелетона). Переопределить биндинг полностью можно в `config/services.php`.

## Команды, поставляемые фреймворком

| Сигнатура | Хендлер | Описание |
|---|---|---|
| `mongo:ensure-indexes` | `Pinecore\Pinecore\Orm\Console\EnsureIndexesCommand` | Раскатывает `#[Index]` атрибуты в MongoDB. См. `architecture/orm.md`. |
| `pg:ensure-indexes` | `Pinecore\Pinecore\Orm\Console\PgEnsureIndexesCommand` | Раскатывает атрибуты индексов в PostgreSQL. См. `architecture/orm-sql.md`. |
| `pg:migrate` | `Pinecore\Pinecore\Orm\Console\PgMigrateCommand` | Применяет SQL-миграции из `orm.migrations_path`. См. `architecture/migrations.md`. |

Регистрируются приложением в его console-конфиге наравне со своими командами — фреймворк сам ничего не регистрирует.

## Argv parsing

Токены argv (после имени скрипта) классифицируются независимо:
- начинается с `--` → опция (`--name=value` → значение, `--name` → флаг `true`);
- иначе → позиционный токен.

Позиционные токены в исходном порядке склеиваются в commandStr и матчатся
сигнатурой команды, поэтому опции и позиционки можно свободно перемешивать:
`bin/console users:create --dry-run admin` эквивалентно
`bin/console users:create admin --dry-run`.
