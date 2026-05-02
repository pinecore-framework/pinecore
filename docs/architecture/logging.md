# Логирование

## Классы

| Файл | Класс | Описание |
|---|---|---|
| `src/Log/JsonStreamLogger.php` | `JsonStreamLogger` | JSON в любой `resource` (stdout, файл, memory) |
| `src/Log/CompositeLogger.php` | `CompositeLogger` | Делегирует нескольким логгерам |
| `src/Log/NullLogger.php` | `NullLogger` | Отбрасывает все сообщения |

Все реализуют `Psr\Log\LoggerInterface`. Куда писать (stdout, файл, что-то ещё) определяется на уровне DI-фабрики в `ContainerFactory` — `JsonStreamLogger` принимает уже открытый стрим и не знает ничего об источнике.

## Формат вывода

JSON-строка на каждое сообщение:
```json
{"ts":"2026-04-06T12:00:00.000Z","level":"error","channel":"app","message":"...","context":{...}}
```

- `ts` — ISO 8601 с миллисекундами
- `channel` — из `log.channel` в config (дефолт `app`)
- `context` — сериализуется; `Throwable` → `{class, message, file, line, trace}`
- PSR-3 интерполяция `{key}` из context работает для строк/scalar

## Конфигурация

| Config key | Описание |
|---|---|
| `log.level` | Минимальный уровень (debug/info/notice/warning/error/critical/alert/emergency), дефолт `debug` |
| `log.file` | Путь к файлу → дополнительный `JsonStreamLogger` пишет в файл (открывается на старте, fail-fast при недоступности) |
| `log.channel` | Имя канала в выводе, дефолт `app` |

`stdout` пишется всегда. Если задан `log.file`, оба логгера заворачиваются в `CompositeLogger`.

## Добавление бэкенда

Перекройте биндинг `LoggerInterface` в `config/services.php` своим — `JsonStreamLogger` принимает любой `resource`:
```php
LoggerInterface::class => function () use ($config) {
    $level   = JsonStreamLogger::levelValue((string) $config->get('log.level', 'debug'));
    $channel = (string) $config->get('log.channel', 'app');

    return new CompositeLogger([
        new JsonStreamLogger(fopen('php://stdout', 'w'), $level, $channel),
        new MyCustomLogger(...),    // syslog, network — что угодно
    ]);
},
```

## Использование

```php
// Inject LoggerInterface через DI
$this->logger->error('Something failed', [
    'exception' => $e,
    'path'      => $request->path,
]);
```
