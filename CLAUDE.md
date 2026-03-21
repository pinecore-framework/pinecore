# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Package

`pronchev/pinecore` — minimal PHP framework for FrankenPHP long-running workers.

**Namespace:** `Pronchev\Pinecore\` → `src/` (PSR-4)

No lint or test commands configured yet.

## Architecture

**Request lifecycle:**

```
HTTP → Caddy (:80) → /worker.php → FrankenPHP worker loop
    Kernel::boot()  [once on startup]
      → Environment::detect() → Config::load() → ContainerFactory::build()
    loop:
      → HttpApplication::handleRequest($_GET, $_POST, $_COOKIE, $_FILES, $_SERVER)
          → Request::fromGlobals()
          → OPTIONS? → CORS headers + 204 (no routing)
          → Router::match() → 404 / 405 / RouteMatch
          → MiddlewarePipeline::run()
          → Controller::__invoke(Request): Response
          → Response + CORS headers → emit()
      → Application::terminate()
      → gc_collect_cycles()
```

**Key components:**

| Path | Purpose |
|---|---|
| `src/Kernel.php` | One-time bootstrap |
| `src/Http/` | `HttpApplication`, `Request`, `Response`, `Router`, `RouteDefinition`, `MiddlewarePipeline`, `HttpException` |
| `src/Console/` | `ConsoleApplication`, `ConsoleRouter`, `ConsoleInput`, `ConsoleOutput`, `ConsoleDefinition` |
| `src/Auth/` | `JwtService`, `AuthMiddleware`, `AuthContext`, `AuthException`, `UserProviderInterface` |
| `src/Log/` | `StdoutLogger`, `FileLogger`, `CompositeLogger`, `NullLogger` |
| `src/Orm/` | `AbstractMongoRepository`, `MongoHydrator`, `EntityMap`, `IdGenerator`, attributes |
| `src/Model/` | Marker interfaces: `Entity`, `MongoEntity`, `SqlEntity`, `Dto` |
| `src/ExceptionHandler.php` | Catches `Throwable` escaping the worker loop |
| `src/Config.php` | Static config loader: `Config::get('section.key')` |
| `src/ContainerFactory.php` | Builds PHP-DI container from `config/services.php` |

## HTTP

```php
// RouteDefinition: method, path, controller class, middleware array
new RouteDefinition('GET', '/users/{id}', GetUserController::class, [AuthMiddleware::class])

// Controller — invokable, resolved via DI
final class GetUserController {
    public function __invoke(Request $request): Response {
        $id = $request->pathParams['id'];           // path param
        $user = $request->get('auth')->user;        // from AuthMiddleware
        return Response::json([...]);
    }
}

// Throw from anywhere — caught centrally
throw new HttpException('Forbidden', 403);
```

## Auth

`AuthMiddleware` requires `UserProviderInterface` to be bound in DI:
```php
// UserProviderInterface: findById(string $id): object
// In app's config/services.php:
UserProviderInterface::class => fn($c) => $c->get(UserRepository::class),
```

`JwtService::issue(string $userId): string` — issues a JWT. Accepts only a string ID, no dependency on app models.

## Logging

```php
// Inject LoggerInterface via DI constructor
$this->logger->error('Something failed', ['exception' => $e, 'path' => $request->path]);
```

Output: JSON to stdout — `ts`, `level`, `channel`, `message`, `context`.
Level: `LOG_LEVEL` env var (default `debug`).
File logging: set `LOG_FILE=/path/to/file.log` — `FileLogger` activates automatically.
To add a backend: extend the `array_filter([...])` in the `LoggerInterface` factory in `config/services.php`.

## ORM

Entities implement `MongoEntity`, use PHP 8 attributes:
```php
#[Collection(name: 'users')]
final class User implements MongoEntity {
    public function __construct(
        #[Id]    public readonly string $id,
        #[Field] public readonly string $email,
    ) {}
}
```

Repository extends `AbstractMongoRepository`, declare `#[ForEntity(Foo::class)]` on the class.
Implement typed `save(Foo $e): Foo { return $this->persist($e); }` — not an override of the protected `persist()`.
`#[Id]` maps to a custom field (`id`), not MongoDB's `_id` (which remains a native ObjectId).
`array` fields (e.g. `string[]`) are hydrated automatically — BSONArray is converted transparently.

## Code Style

EditorConfig enforces:
- PHP: UTF-8, LF, 4-space indent, 120-char line limit (PSR-12)
- JS/TS: 2-space indent, 100-char line limit
- Templates (Blade/Twig), YAML, JSON, Docker: 2-space indent
