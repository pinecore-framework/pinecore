# Ядро, Config, Environment, ContainerFactory

## Kernel (`src/Kernel.php`)

Тонкий хелпер оркестрации: ENV → Config → контейнер. Без статического состояния — entrypoint захватывает возвращаемый контейнер и из него резолвит первый сервис; дальше всё через DI.

```php
Kernel::boot(string $basePath): Container  // ENV → Config → ContainerFactory
```

```php
// worker.php / bin/console
$container = Kernel::boot(__DIR__);
$runner    = $container->get(WorkerRunner::class);
```

## Environment (`src/Environment.php`)

```php
Environment::detect(): self   // getenv('APP_ENV') ?: 'dev'
$env->name(): string
$env->is(string $name): bool
$env->isProd(): bool          // name === 'prod'
```

## Config (`src/Config.php`)

**Загрузка** (`Config::load($configDir, $env)`):
1. Все `$configDir/*.php` — ключ = имя файла без расширения
2. Deep-merge `$configDir/env/{envName}.php` (если есть)
3. Deep-merge `$configDir/env/local.php` (если есть, всегда поверх)

**Доступ:**
```php
$config->get('section.key', $default)   // dot-notation, mixed return — escape hatch
```

Для типизированного fail-fast чтения предпочтителен новый API. Если значение есть, но тип не тот — `\RuntimeException` (никаких silent-кастов вроде `(int) '8mb' === 8`):

| Метод | Поведение |
|---|---|
| `requireString($key)` / `requireInt` / `requireBool` / `requireArray` | Обязательное значение нужного типа. Отсутствует → `RuntimeException("missing required key …")`. Не того типа → `RuntimeException("key … must be X, got Y")` |
| `requireStringList($key)` | `list<string>`. Отвергает ассоциативный массив и нестроковые элементы (с указанием индекса) |
| `getString($key, $default)` / `getInt` / `getBool` / `getArray` | Если ключа нет — дефолт. Если есть, но тип не тот — `RuntimeException` |

Используется во фреймворке: `JwtService` (`requireString`/`requireInt`), `CorsPolicy` (`requireStringList`), `HttpApplication` (`getInt`), `ContainerFactory` (`getString`/`getInt`), `EnsureIndexesCommand` (`getArray`).

Merge при загрузке: рекурсивный для assoc-массивов, replace для списков (`array_values === array`).

## ContainerFactory (`src/ContainerFactory.php`)

```php
ContainerFactory::build(Environment $env, Config $config, string $basePath): Container
```

- Autowiring включён всегда
- В prod: `$builder->enableCompilation($basePath . '/var/cache/prod/')`. Компилируется **один раз** —
  при изменении определений чистить `var/cache/prod`
- Автоматически регистрирует `Config::class`, `Environment::class`, `LoggerInterface::class`
  и строку `ContainerFactory::BASE_PATH` (`'app.base_path'` — корень приложения из `Kernel::boot`)
  в контейнере — любой класс может получить их через DI без ручного биндинга в `services.php`
- Фреймворковые фабрики — статические методы `ContainerFactory::createLogger/createRouter/createConsoleRouter`
  через `DI\factory([...])`; `routes.php`/`commands.php` читаются внутри фабрики по `BASE_PATH`,
  а не захватываются из `build()` — см. «Компиляция и замыкания» ниже
- `LoggerInterface` по умолчанию резолвится в `JsonStreamLogger`, пишущий в `php://stdout`;
  если задан `log.file` — добавляется второй `JsonStreamLogger` для файла, оба
  заворачиваются в `CompositeLogger`. Файл открывается на старте — недоступный путь
  валит boot.
- Загружает `$basePath/config/routes.php` (если есть) — файл возвращает `RouteDefinition[]`,
  фреймворк автоматически создаёт `Router` и регистрирует его в контейнере
- Загружает `$basePath/config/commands.php` (если есть) — файл возвращает `ConsoleDefinition[]`,
  фреймворк автоматически создаёт `ConsoleRouter` и регистрирует его в контейнере. Если файла
  нет — `ConsoleRouter` всё равно резолвится с пустым списком, чтобы `bin/console help` работал
  на голом скелетоне
- Загружает `$basePath/config/services.php` (если есть) — файл возвращает
  `callable($builder, $config, $basePath)`; загружается после routes.php/commands.php и может
  переопределить любые определения, включая `Router::class` и `ConsoleRouter::class`

**`config/routes.php`** (конвенция, предпочтительный способ):
```php
use Pinecore\Pinecore\Http\RouteDefinition;

return [
    new RouteDefinition('GET',  '/users/{id}', GetUserController::class, [AuthMiddleware::class]),
    new RouteDefinition('POST', '/users',      CreateUserController::class, [AuthMiddleware::class]),
];
```

**`config/services.php`** (для DI-биндингов и переопределений):
```php
return function (ContainerBuilder $builder, Config $config, string $basePath): void {
    $builder->addDefinitions([
        UserProviderInterface::class => fn($c) => $c->get(UserRepository::class),
    ]);
};
```

### Компиляция и замыкания (prod)

В prod PHP-DI копирует исходник каждого замыкания-фабрики в сгенерированный класс, поэтому
замыкание **не может ничего захватывать**. `ContainerBuilder::build()` падает на старте с
`InvalidDefinition: Cannot compile closures which import variables using the `use` keyword`
(или `…which use $this or self/static/parent references`). Ошибка не воспроизводится в dev —
там контейнер не компилируется.

Запрещено в фабриках (и во фреймворке, и в `config/services.php`):
- `function () use ($config) { … }` — явный захват;
- `fn() => new PDO($config->getString('db.dsn'))` — arrow-функция захватывает `$config` неявно,
  для компилятора это тот же `use`;
- `self::`, `static::`, `$this` внутри замыкания.

Правильно — всё нужное приходит **параметрами**, PHP-DI резолвит их из контейнера по type-hint
(класс или `ContainerInterface`; скаляры по типу не резолвятся — брать через `$c->get(...)`):
```php
return function (ContainerBuilder $builder, Config $config, string $basePath): void {
    $builder->addDefinitions([
        PDO::class => fn(Config $config) => new PDO($config->getString('db.dsn'), …),
        SomeService::class => fn(ContainerInterface $c) => new SomeService(
            $c->get(ContainerFactory::BASE_PATH) . '/storage',
        ),
        LoggerInterface::class => DI\factory([LoggerFactory::class, 'create']),   // статический метод — тоже ок
    ]);
};
```
`$config` и `$basePath`, которые `services.php` получает аргументами, годятся только для
решений на этапе сборки (`if ($config->getBool(...)) $builder->addDefinitions(...)`), но не
внутри фабрик. Регрессия во фреймворке ловится `tests/ContainerCompilationTest.php`.
