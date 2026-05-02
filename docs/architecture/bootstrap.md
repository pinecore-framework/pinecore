# Ядро, Config, Environment, ContainerFactory

## Kernel (`src/Kernel.php`)

Статический класс, хранит единственный инстанс контейнера.

```php
Kernel::boot(string $basePath): Container  // ENV → Config → ContainerFactory
Kernel::container(): Container             // бросает RuntimeException если не вызван boot()
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
$config->get('section.key', $default)   // dot-notation, deep lookup
$config->get('app.cors')                // возвращает array
```

Merge: рекурсивный для assoc-массивов, replace для списков (array_values === array).

## ContainerFactory (`src/ContainerFactory.php`)

```php
ContainerFactory::build(Environment $env, Config $config, string $basePath): Container
```

- Autowiring включён всегда
- В prod: `$builder->enableCompilation($basePath . '/var/cache/prod/')`
- Автоматически регистрирует `Config::class`, `Environment::class` и `LoggerInterface::class`
  в контейнере — любой класс может получить их через DI без ручного биндинга в `services.php`
- `LoggerInterface` по умолчанию резолвится в `JsonStreamLogger`, пишущий в `php://stdout`;
  если задан `log.file` — добавляется второй `JsonStreamLogger` для файла, оба
  заворачиваются в `CompositeLogger`. Файл открывается на старте — недоступный путь
  валит boot.
- Загружает `$basePath/config/routes.php` (если есть) — файл возвращает `RouteDefinition[]`,
  фреймворк автоматически создаёт `Router` и регистрирует его в контейнере
- Загружает `$basePath/config/services.php` (если есть) — файл возвращает
  `callable($builder, $config, $basePath)`; загружается после routes.php и может переопределить
  любые определения, включая `Router::class`

**`config/routes.php`** (конвенция, предпочтительный способ):
```php
use Pronchev\Pinecore\Http\RouteDefinition;

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
