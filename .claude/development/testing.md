# Testing & Debugging

## Запуск воркера локально

```bash
frankenphp run --config Caddyfile
```

Убедись, что переменные окружения заданы (`.env` или `environment.php`).

---

## Отладка запросов

Включи подробное логирование через конфиг:

```php
Config::get('log.level') // DEBUG для максимального вывода
```

Логи пишутся через `LoggerInterface` (Monolog). Смотри `architecture/logging.md` для деталей.

---

## Частые проблемы

### Воркер не стартует

- Проверь синтаксис `Caddyfile` и путь до `worker.php`
- FrankenPHP требует `FRANKENPHP_CONFIG` или явного указания воркер-скрипта
- Проверь, что `Kernel::init()` вызван до первого запроса

### Маршрут не найден (404)

- Убедись, что файл с `RouteDefinition` лежит в `routes/` (autoload подхватывает автоматически)
- Проверь HTTP-метод и путь: параметры вида `{id}` чувствительны к паттерну

### JWT не валидируется

- Проверь, что `Config::get('auth.secret')` не пустой
- Убедись, что `AuthMiddleware` зарегистрирован в контейнере
- Время жизни токена — `Config::get('auth.ttl')`

### Ошибка CORS

- `CorsMiddleware` требует явного конфига (список разрешённых origins)
- Если конфиг не задан — бросается `RuntimeException` при старте

### MongoDB не подключается

- Проверь DSN в конфиге: `Config::get('mongodb.dsn')`
- Проверь, что MongoDB-сервис запущен и доступен из воркера

---

## Инспекция контейнера

```php
// В dev-режиме: посмотреть все зарегистрированные сервисы
$ids = Kernel::getContainer()->getServiceIds();
```
