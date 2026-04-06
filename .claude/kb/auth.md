# Аутентификация (JWT)

## Компоненты

| Файл | Класс | Роль |
|---|---|---|
| `src/Auth/JwtService.php` | `JwtService` | Выдача и верификация JWT (HS256, lcobucci/jwt) |
| `src/Auth/AuthMiddleware.php` | `AuthMiddleware` | Bearer → JwtService → UserProvider → AuthContext |
| `src/Auth/AuthContext.php` | `AuthContext` | Обёртка над объектом пользователя |
| `src/Auth/UserProviderInterface.php` | `UserProviderInterface` | Контракт: `findById(string): ?object` |
| `src/Auth/AuthException.php` | `AuthException` | → 401 в HttpApplication::dispatch() |

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
