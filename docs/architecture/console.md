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

Регистрируются приложением в его console-конфиге наравне со своими командами — фреймворк сам ничего не регистрирует.

## Argv parsing

Токены argv (после имени скрипта) классифицируются независимо:
- начинается с `--` → опция (`--name=value` → значение, `--name` → флаг `true`);
- иначе → позиционный токен.

Позиционные токены в исходном порядке склеиваются в commandStr и матчатся
сигнатурой команды, поэтому опции и позиционки можно свободно перемешивать:
`bin/console users:create --dry-run admin` эквивалентно
`bin/console users:create admin --dry-run`.
