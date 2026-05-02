# Console

## Компоненты

| Файл | Класс | Описание |
|---|---|---|
| `src/Console/ConsoleApplication.php` | `ConsoleApplication` | Точка входа: парсит argv, матчит команду, запускает handler |
| `src/Console/ConsoleRouter.php` | `ConsoleRouter` | Аналог HTTP Router для консольных команд |
| `src/Console/ConsoleDefinition.php` | `ConsoleDefinition` | signature, handler class, description, options[] |
| `src/Console/OptionDefinition.php` | `OptionDefinition` | name, description, default (false = флаг) |
| `src/Console/ConsoleInput.php` | `ConsoleInput` | Распарсенные path params + options |
| `src/Console/ConsoleOutput.php` | `ConsoleOutput` | Вывод, exit code |
| `src/Console/ConsoleMatch.php` | `ConsoleMatch` | Результат матчинга (аналог RouteMatch) |

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

        $output->writeln('Done');
        $output->setExitCode(0);
    }
}
```

## Запуск

```php
// bin/console
$container = Kernel::boot(__DIR__ . '/..');
$app       = $container->get(ConsoleApplication::class);
exit($app->run($argv));
```

Команды: `bin/console <signature> [--option=value] [--flag]`
Help: `bin/console help` или `bin/console help <signature>`

## Команды, поставляемые фреймворком

| Сигнатура | Хендлер | Описание |
|---|---|---|
| `mongo:ensure-indexes` | `Pronchev\Pinecore\Orm\Console\EnsureIndexesCommand` | Раскатывает `#[Index]` атрибуты в MongoDB. См. `architecture/orm.md`. |

Регистрируются приложением в его console-конфиге наравне со своими командами — фреймворк сам ничего не регистрирует.

## Argv parsing

`ConsoleInput::parse($commandStr, $pathParams, $args, $options)`:
- `$args` = `array_slice($argv, 2)` (после имени команды)
- `--name=value` → опция
- `--name` без `=` → флаг (true)
