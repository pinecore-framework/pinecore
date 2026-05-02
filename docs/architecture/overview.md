# Архитектура и жизненный цикл запроса

## Общая схема

```
HTTP → /worker.php → worker loop
    Kernel::boot($basePath)  [один раз при старте]
      → Environment::detect()        // читает APP_ENV, дефолт 'dev'
      → Config::load($configDir)     // config/*.php + config/env/{env}.php + config/env/local.php
      → ContainerFactory::build()    // PHP-DI, в prod компилируется в var/cache/prod/
    loop (WorkerRunner::run($loop)):
      $loop($handler) → keepRunning           // адаптер ходит в свой рантайм за запросом
        $handler($get, $post, $cookie, $files, $server, $rawBody) →
          HttpApplication::handleRequest($get, $post, $cookie, $files, $server, $rawBody)
          → Request::fromGlobals()           // парсит метод, путь, заголовки, JSON body
          → OPTIONS? → CorsPolicy::preflightHeaders($origin) + 204
                       // origin валидируется по allowlist; default-deny при miss
          → Router::match(method, path)      // regex-компиляция {param} → именованные группы
          → 404 / 405+Allow / RouteMatch
          → MiddlewarePipeline::run()        // каждый middleware меняет Request через withContext()
          → $controller($request): Response  // DI-resolved invokable
          → $response->withHeaders(corsHeaders())->emit()
      )
      → HttpApplication::terminate()         // хук для закрытия ресурсов (сейчас пустой)
      → gc_collect_cycles()
      → если !$keepRunning или достигнут worker.max_requests — выход из цикла
```

## Карта компонентов

| Файл | Класс | Роль |
|---|---|---|
| `src/Kernel.php` | `Kernel` | Статический bootstrap, хранит контейнер |
| `src/Environment.php` | `Environment` | Читает `APP_ENV`, метод `isProd()` |
| `src/Config.php` | `Config` | Загружает `config/*.php`, deep-merge с env-оверрайдами; `get('a.b.c')` |
| `src/ContainerFactory.php` | `ContainerFactory` | Строит PHP-DI контейнер; в prod включает compilation |
| `src/Http/WorkerRunner.php` | `WorkerRunner` | Worker-цикл, MAX_REQUESTS, gc |
| `src/Http/HttpApplication.php` | `HttpApplication` | CORS, роутинг, dispatch, обработка исключений |
| `src/Http/Request.php` | `Request` | Иммутабельный; `body()`, `get(key)`, `withContext()` |
| `src/Http/Response.php` | `Response` | `json()`, `error()`, `withHeader()`, `emit()` |
| `src/Http/Router.php` | `Router` | Regex-компиляция маршрутов, возвращает `RouteMatch` |
| `src/Http/CorsPolicy.php` | `CorsPolicy` | Allowlist по Origin, preflight/simple-заголовки, Vary |
| `src/Http/MiddlewarePipeline.php` | `MiddlewarePipeline` | Последовательно прогоняет middleware через DI |
| `src/Http/RouteDefinition.php` | `RouteDefinition` | method, path, controller, middleware[] |
| `src/Http/HttpException.php` | `HttpException` | Выбрасывается из любого места → Response::error |
| `src/Auth/AuthMiddleware.php` | `AuthMiddleware` | Bearer JWT → AuthContext в request |
| `src/Auth/JwtService.php` | `JwtService` | HS256, issue/verify, lcobucci/jwt |
| `src/Log/JsonStreamLogger.php` | `JsonStreamLogger` | JSON в любой `resource` (stdout/файл), PSR-3 |
| `src/Orm/AbstractMongoRepository.php` | `AbstractMongoRepository` | persist/findById/delete/findOneWhere |
| `src/Orm/MongoHydrator.php` | `MongoHydrator` | hydrate/dehydrate, поддержка Embedded/EmbeddedList |
| `src/ExceptionHandler.php` | `ExceptionHandler` | Ловит Throwable вне dispatch (critical лог) |

## Обработка исключений в dispatch

В `HttpApplication::dispatch()` ловятся:
- `AuthException` → 401
- `HttpException` → код из исключения
- `\JsonException` → 400 (невалидный JSON body)
- `\Throwable` → логируется, 500

Исключения, вылетающие **за пределы** `handleRequest()` (т.е. до/после dispatch), ловит `ExceptionHandler` в `WorkerRunner`.
