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

## CORS

CORS-политика инкапсулирована в `src/Http/CorsPolicy.php` (DI-резолвится автовайрингом). Ключ `app.cors` в конфиге обязателен — `CorsPolicy` бросает в конструкторе, если секции нет.

```php
// config/app.php
return [
    'cors' => [
        // Точный allowlist. Допустимо ['*'] — но только если allow_credentials=false.
        'allowed_origins'   => ['https://app.example.com', 'https://staging.example.com'],
        'allowed_methods'   => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'allowed_headers'   => ['Content-Type', 'Authorization'],
        'allow_credentials' => false,
        'max_age'           => 600,
    ],
];
```

**Резолв `Access-Control-Allow-Origin`:**

| Запрос | Конфиг | Что эмитится |
|---|---|---|
| Без `Origin` | любой | ничего — same-origin / curl, CORS-заголовки не нужны |
| `Origin` есть, allowlist `['*']`, credentials=false | wildcard | `Allow-Origin: *`, без `Vary` |
| `Origin` есть, allowlist `['*']`, credentials=true | wildcard + creds | **ошибка конфига** — `\RuntimeException` в конструкторе. По спеке `*` несовместим с credentials |
| `Origin` есть, в allowlist | exact match | `Allow-Origin: <тот origin>` + `Vary: Origin` |
| `Origin` есть, не в allowlist | miss | заголовок не эмитится — браузер интерпретирует как fail |

**`Vary: Origin`** добавляется ко всем ответам кроме чистого `['*']` без credentials — защита от cache-poisoning, когда CDN/прокси может отдать кэшированный ответ другого origin'а.

**`Access-Control-Allow-Credentials: true`** эмитится только если включено в конфиге **и** мы выдали `Allow-Origin`.

**Preflight (OPTIONS)** обрабатывается до роутинга и middleware. Для разрешённого origin'а добавляются `Allow-Methods`, `Allow-Headers`, `Max-Age`. Для запрещённого — только `Vary: Origin` (без CORS-заголовков), 204.

**Simple-ответы** (non-OPTIONS) не содержат `Allow-Methods` / `Allow-Headers` — по спеке они preflight-only.

## HttpApplication — конфигурационные зависимости

`HttpApplication` зависит от `CorsPolicy`, `Router`, `MiddlewarePipeline`, `Config`, `LoggerInterface` — всё через DI-конструктор.

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
