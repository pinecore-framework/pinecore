# Логирование

## Классы

| Файл | Класс | Описание |
|---|---|---|
| `src/Log/StdoutLogger.php` | `StdoutLogger` | JSON → php://stdout |
| `src/Log/FileLogger.php` | `FileLogger` | JSON → файл |
| `src/Log/CompositeLogger.php` | `CompositeLogger` | Делегирует нескольким логгерам |
| `src/Log/NullLogger.php` | `NullLogger` | Отбрасывает все сообщения |

Все реализуют `Psr\Log\LoggerInterface`.

## Формат вывода (StdoutLogger)

JSON-строка на каждое сообщение:
```json
{"ts":"2026-04-06T12:00:00.000Z","level":"error","channel":"app","message":"...","context":{...}}
```

- `ts` — ISO 8601 с миллисекундами
- `channel` — из `log.channel` в config (дефолт `app`)
- `context` — сериализуется; `Throwable` → `{class, message, file, line, trace}`
- PSR-3 интерполяция `{key}` из context работает для строк/scalar

## Конфигурация

| Env / Config key | Описание |
|---|---|
| `LOG_LEVEL` / `log.level` | Минимальный уровень (debug/info/notice/warning/error/critical/alert/emergency), дефолт `debug` |
| `LOG_FILE` / `log.file` | Путь к файлу → `FileLogger` включается автоматически |
| `log.channel` | Имя канала в выводе, дефолт `app` |

## Добавление бэкенда

В `config/services.php` в фабрике `LoggerInterface`:
```php
LoggerInterface::class => fn($c) => new CompositeLogger(array_filter([
    $c->get(StdoutLogger::class),
    $config->get('log.file') ? $c->get(FileLogger::class) : null,
    // добавить сюда
])),
```

## Использование

```php
// Inject LoggerInterface через DI
$this->logger->error('Something failed', [
    'exception' => $e,
    'path'      => $request->path,
]);
```
