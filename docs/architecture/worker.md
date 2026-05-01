# Worker и запуск

## Entrypoint (`worker.php` приложения)

Фреймворк не привязан к конкретному рантайму. `WorkerRunner::run()` без аргументов обрабатывает один запрос и завершается (классический SAPI/FPM/CGI). Чтобы крутить worker-петлю, приложение передаёт свой адаптер:

```php
use Pronchev\Pinecore\Kernel;
use Pronchev\Pinecore\Http\WorkerRunner;

require __DIR__ . '/vendor/autoload.php';

Kernel::boot(__DIR__);
$runner = Kernel::container()->get(WorkerRunner::class);

// Один запрос:
$runner->run();

// Или worker-петля — приложение передаёт адаптер своего рантайма:
$runner->run(function ($handler) {
    // вызов $handler() обработает один запрос
    // вернуть true — продолжать цикл, false — выйти
});
```

Адаптер получает `callable $handler` (обработать один запрос) и возвращает `bool` — продолжать ли цикл. Лимит итераций (`worker.max_requests` из конфига, 0 = без лимита), `$app->terminate()` и `gc_collect_cycles()` отрабатывает сам `WorkerRunner` между итерациями. `WorkerRunner` резолвится через DI с фабрикой в `ContainerFactory`, забирающей лимит из `Config`.

## WorkerRunner (`src/Http/WorkerRunner.php`)

```php
final class WorkerRunner
{
    public function __construct(
        private readonly HttpApplication  $app,
        private readonly ExceptionHandler $exceptionHandler,
        private readonly int              $maxRequests = 0,   // 0 = без лимита
    ) {}

    public function run(?callable $loop = null): void
    {
        if ($loop === null) {
            $this->handle();
            $this->app->terminate();
            return;
        }

        $handler = fn () => $this->handle();

        for ($n = 0; !$this->maxRequests || $n < $this->maxRequests; ++$n) {
            $keepRunning = $loop($handler);

            $this->app->terminate();   // хук: закрытие ресурсов (сейчас пустой)
            gc_collect_cycles();

            if (!$keepRunning) break;
        }
    }

    private function handle(): void
    {
        try {
            $this->app->handleRequest($_GET, $_POST, $_COOKIE, $_FILES, $_SERVER);
        } catch (\Throwable $e) {
            $this->exceptionHandler->handleException($e);  // critical лог
        }
    }
}
```

## Graceful shutdown

В worker-loop режиме `WorkerRunner` устанавливает обработчики `SIGTERM` и `SIGINT` (`pcntl_async_signals(true)`). При получении сигнала:

1. Текущий запрос дорабатывает до конца — никаких принудительных прерываний.
2. После возврата управления из адаптера и вызова `$app->terminate()` цикл выходит, даже если адаптер хотел бы продолжать.

Это даёт оркестратору (k8s, systemd, `docker stop`) корректно завершить воркер: посылает SIGTERM, ждёт grace period, по истечении — SIGKILL. PHP не умеет безопасно прерывать выполнение посередине запроса, поэтому форсированный таймаут — забота оркестратора, не фреймворка.

В single-request режиме (`run()` без аргументов) сигналы не цепляются — там ими управляет SAPI/CGI.

Без `pcntl` экстеншена обработчики просто не ставятся — цикл работает, но без graceful shutdown. На `php:8.2-cli-alpine` (наш базовый образ) `pcntl` есть.

### Программное завершение

`WorkerRunner::requestShutdown()` — публичный метод. Эквивалент сигнала "программно". Полезен, например, для:

```php
$runner->run(function ($handler) use ($runner, $health) {
    $handler();
    if ($health->isUnhealthy()) {
        $runner->requestShutdown();   // выйти после следующего запроса
    }
    return true;
});
```

## Конфигурация

Воркер читается из `Config` (ключ `worker.max_requests`); приложение само решает, мапить ли его на env-переменную:

```php
// config/app.php
return [
    'worker' => [
        'max_requests' => (int) env('MAX_REQUESTS', 0),  // 0 = без лимита
    ],
];
```

Прочие env-переменные, на которые ссылается фреймворк:

| Переменная | По умолчанию | Описание |
|---|---|---|
| `APP_ENV` | `dev` | Среда исполнения (читает `Environment::detect()`) |
| `LOG_LEVEL` | `debug` | Минимальный уровень логирования |
| `LOG_FILE` | — | Путь к файлу лога (активирует FileLogger) |
| `JWT_SECRET` | обязателен | HMAC-ключ для JWT (≥ 32 байт) |
