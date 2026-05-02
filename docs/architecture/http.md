# HTTP слой

## RouteDefinition (`src/Http/RouteDefinition.php`)

```php
new RouteDefinition(
    method:     'GET',
    path:       '/users/{id}',
    controller: GetUserController::class,
    middleware: [AuthMiddleware::class],   // опционально
)
```

## Router (`src/Http/Router.php`)

- Компилирует `{param}` → именованные regex-группы `(?P<param>[^/]+)`
- `match(method, path): RouteMatch`
  - Сначала проверяет все маршруты на совпадение пути
  - Если путь совпал, но метод нет → `RouteMatch::methodNotAllowed($allowedMethods)`
  - Если ничего → `RouteMatch::notFound()`

## MiddlewarePipeline (`src/Http/MiddlewarePipeline.php`)

Резолвит каждый middleware через DI и вызывает `process(Request): Request` последовательно.
Middleware может изменять request через `withContext()` или бросать исключение.

**Интерфейс middleware:**
```php
interface MiddlewareInterface
{
    public function process(Request $request): Request;
}
```

## Request (`src/Http/Request.php`)

Иммутабельный объект. Конструктор принимает именованные параметры.

```php
$request->method                    // 'GET', 'POST', ...
$request->path                      // '/users/123'
$request->query                     // $_GET
$request->pathParams                // ['id' => '123'] — устанавливается роутером
$request->headers                   // lowercase-hyphen: 'content-type', 'authorization'
$request->cookies
$request->files
$request->body()                    // распарсенное тело (см. Content-Type ниже)
$request->input('email')            // значение поля из body или null
$request->input('email', 'def')     // с дефолтом
$request->get('auth')               // значение из context, установленного middleware
$request->withContext('key', $value): self   // иммутабельное добавление в context
```

**Разбор заголовков:** `HTTP_*` из `$_SERVER` → lowercase-hyphen. `CONTENT_TYPE` и `CONTENT_LENGTH` нормализуются дополнительно — у них в `$_SERVER` нет префикса `HTTP_` (CGI, RFC 3875).

### Парсинг тела по `Content-Type`

`Request::fromGlobals` решает, как наполнить `body` исходя из заголовка:

| Content-Type | Источник `body()` |
|---|---|
| `application/json` (с любым `; charset=...`) | `json_decode($rawBody, true)` |
| `application/x-www-form-urlencoded`, метод `POST` | `$_POST` |
| `application/x-www-form-urlencoded`, прочие методы (`PUT`/`PATCH`/...) | `parse_str($rawBody, ...)` |
| `multipart/form-data` (boundary) | `$_POST` |
| Пусто или другое | `[]` |

**Ошибки парсинга** бросают `HttpException(400)` прямо из `fromGlobals`:
- невалидный JSON → `'Invalid JSON body'`
- JSON-скаляр или `null` на корне → `'JSON body must be an object or array'` (top-level `[...]` валиден)

`HttpApplication::dispatch` ловит `HttpException` и формирует `Response::error(message, 400)`. Контроллер до невалидного тела не доходит.

## Response (`src/Http/Response.php`)

```php
Response::json($data, $status = 200): self   // Content-Type: application/json
Response::error($message, $status): self     // json(['error' => $message], $status)

$response->withHeader($name, $value): self
$response->withHeaders($headers): self
$response->emit(): void                      // http_response_code + headers + echo body
```

## HttpException (`src/Http/HttpException.php`)

```php
throw new HttpException('Forbidden', 403);
// → перехватывается в HttpApplication::dispatch() → Response::error(message, code)
```

## HttpApplication::handleRequest — источники данных

```php
$app->handleRequest(
    array $get, array $post, array $cookie, array $files, array $server,
    ?string $rawBody = null,
): void
```

`$get…$server` всегда передаются вызывающим (адаптером воркер-рантайма или `WorkerRunner` в SAPI-режиме). Тело — два режима:

- `$rawBody = null` (по умолчанию) — `HttpApplication::readRawBody()` читает `php://input`. Это путь для FPM/CGI/Apache mod_php, где PHP runtime сам перенаполняет stream под запрос.
- `$rawBody = '<строка>'` — используется как есть, `php://input` не трогается. Это путь для воркер-рантаймов (RoadRunner, FrankenPHP в worker-mode и т.п.), где `php://input` не пересоздаётся между итерациями.

`readRawBody()` оставлен `protected` как тестовый хук для подмены SAPI-чтения в анонимных сабклассах.

## HttpApplication — конфигурационные зависимости

`HttpApplication` требует наличия ключа `cors` в `config/app.php`. Конфиг читается при каждом
запросе (в т.ч. OPTIONS-preflight). Если ключ отсутствует — бросается
`\RuntimeException('app.cors config is missing')`.

```php
// config/app.php
return [
    'cors' => [
        'origins'  => '*',
        'methods'  => 'GET, POST, PUT, DELETE, OPTIONS',
        'headers'  => 'Content-Type, Authorization',
        'max_age'  => '86400',
    ],
];
```

## Лимит размера тела запроса

`HttpApplication` ограничивает размер тела через ключ `http.max_body_bytes`
(дефолт — 8 MiB; `0` отключает лимит). Проверка двойная:

1. **До чтения** — если `Content-Length` превышает лимит, тело даже не читается, отдаётся `413 Payload Too Large`.
2. **После чтения** — если клиент соврал в `Content-Length` или не прислал его, фактический размер всё равно валидируется.

```php
// config/app.php
return [
    'http' => [
        'max_body_bytes' => 8 * 1024 * 1024,
    ],
    // ...
];
```
