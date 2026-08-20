# Аутентификация (JWT)

## Компоненты

| Файл | Класс | Роль |
|---|---|---|
| `src/Auth/JwtService.php` | `JwtService` | Выдача и верификация JWT (HS256, lcobucci/jwt) |
| `src/Auth/AuthMiddleware.php` | `AuthMiddleware` | Bearer → JwtService → UserProvider → AuthContext |
| `src/Auth/AuthContext.php` | `AuthContext` | Обёртка над объектом пользователя |
| `src/Auth/UserProviderInterface.php` | `UserProviderInterface` | Контракт: `findById(string): ?object` |
| `src/Auth/AuthException.php` | `AuthException` | Исключение фреймворка (`final`), реализует маркер → 401 |
| `src/Auth/AuthFailureInterface.php` | `AuthFailureInterface` | Маркер-интерфейс (без методов): любое исключение с ним → 401 в `HttpApplication::dispatch()` |

## JwtService (`src/Auth/JwtService.php`)

Конфигурируется через Config:
- `jwt.secret` — HMAC-ключ (обязателен, иначе RuntimeException при старте)
- `jwt.access_ttl` — TTL в секундах

```php
$jwt->issue(string $userId): string     // sub = $userId, iat, exp
$jwt->verify(string $tokenString): string  // возвращает sub (userId), бросает AuthException
```

Использует `lcobucci/jwt`, HS256, `StrictValidAt` (проверяет exp с текущим временем).

## AuthMiddleware (`src/Auth/AuthMiddleware.php`)

1. Читает заголовок `authorization` (lowercase в Request)
2. Ожидает формат `Bearer <token>`
3. `JwtService::verify()` → `userId`
4. `UserProviderInterface::findById($userId)` → user object
5. `null` → `AuthException('User not found')`
6. Возвращает `$request->withContext('auth', new AuthContext($user))`

```php
// В контроллере:
$auth = $request->get('auth');  // AuthContext
$user = $auth->user;            // объект пользователя (тип зависит от приложения)
```

## Подключение в приложении

В `config/services.php` нужно забиндить `UserProviderInterface`:
```php
UserProviderInterface::class => fn($c) => $c->get(UserRepository::class),
```

`UserRepository` должен реализовывать `UserProviderInterface` (`findById(string $id): ?object`).

### Свои ошибки аутентификации → 401

`AuthException` — `final`, наследовать его не нужно и нельзя. Чтобы доменная ошибка приложения
(«неверный логин/пароль», «refresh-токен изъят») попала в тот же 401-контракт, пометьте **своё**
исключение маркером `AuthFailureInterface`:

```php
use Pinecore\Pinecore\Auth\AuthFailureInterface;

final class InvalidCredentials extends \RuntimeException implements AuthFailureInterface {}

// в контроллере или middleware:
throw new InvalidCredentials('Invalid login or password');   // → 401 {"error":"Invalid login or password"}
```

Работает и из контроллера, и из middleware (`MiddlewarePipeline::run` не ловит исключения — всё
доходит до `dispatch()`). Сообщение исключения уходит клиенту как есть — не кладите в него ничего
лишнего. Маркер ловится **раньше** `HttpException` и `\Throwable`: исключение с маркером отвечает
401 независимо от того, какой код оно само несёт (практически неактуально — `HttpException` `final`,
но правило порядка catch-блоков именно такое).
