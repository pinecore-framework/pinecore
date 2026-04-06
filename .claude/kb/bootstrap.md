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
- Загружает `$basePath/config/services.php` — файл должен вернуть `callable($builder, $config, $basePath)`

**Пример `config/services.php`:**
```php
return function (ContainerBuilder $builder, Config $config, string $basePath): void {
    $builder->addDefinitions([
        UserProviderInterface::class => fn($c) => $c->get(UserRepository::class),
        Router::class => fn() => new Router([
            new RouteDefinition('GET', '/users/{id}', GetUserController::class, [AuthMiddleware::class]),
        ]),
    ]);
};
```
