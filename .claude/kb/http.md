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
$request->method          // 'GET', 'POST', ...
$request->path            // '/users/123'
$request->query           // $_GET
$request->pathParams      // ['id' => '123'] — устанавливается роутером
$request->headers         // lowercase-hyphen: 'content-type', 'authorization'
$request->cookies
$request->files
$request->body()          // decoded JSON array (только если Content-Type: application/json)
$request->get('auth')     // значение из context, установленного middleware
$request->withContext('key', $value): self   // иммутабельное добавление в context
```

**Разбор заголовков:** `HTTP_*` из `$_SERVER` → lowercase-hyphen. `CONTENT_TYPE` тоже нормализуется.

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
