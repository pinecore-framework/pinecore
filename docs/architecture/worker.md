# Worker и запуск

## Entrypoint (`worker.php` приложения)

Фреймворк не привязан к конкретному рантайму. У `WorkerRunner::run()` два режима:

**1. Single-request (без аргументов).** Один запрос и выход — классический SAPI/FPM/CGI. PHP runtime сам перенаполняет `$_GET, $_POST, $_COOKIE, $_FILES, $_SERVER` и `php://input` под каждый запрос, поэтому `WorkerRunner` читает их напрямую.

```php
$runner = Kernel::container()->get(WorkerRunner::class);
$runner->run();
```

**2. Worker-loop (с адаптером).** Долгоживущий процесс под RoadRunner / FrankenPHP / ReactPHP / Swoole / Amphp. В этих рантаймах суперглобалы и `php://input` фиксируются на старте процесса и **не обновляются между итерациями**, поэтому `WorkerRunner` их в этом режиме не трогает: данные текущего запроса передаёт адаптер.

```php
$runner->run(function ($handler) use ($rrWorker) {
    $req = $rrWorker->waitRequest();        // блокирующее чтение запроса
    if ($req === null) return false;        // воркер просят выйти

    $handler(
        $req->getQuery(),                   // $_GET-эквивалент
        $req->getPost(),                    // $_POST
        $req->getCookies(),                 // $_COOKIE
        $req->getUploads(),                 // $_FILES
        $req->getServer(),                  // $_SERVER (REQUEST_METHOD, REQUEST_URI, заголовки HTTP_*)
        $req->getBody(),                    // raw body, не php://input
    );

    return true;                            // продолжать цикл
});
```

Адаптер получает `callable $handler` с сигнатурой `(array $get, array $post, array $cookie, array $files, array $server, string $rawBody): void` и возвращает `bool` — продолжать ли цикл. Лимит итераций (`worker.max_requests` из конфига, 0 = без лимита), `$app->terminate()` и `gc_collect_cycles()` отрабатывает сам `WorkerRunner` между итерациями. `WorkerRunner` резолвится через DI с фабрикой в `ContainerFactory`, забирающей лимит из `Config`.

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
            // SAPI: PHP runtime наполняет суперглобалы и php://input.
            try {
                $this->app->handleRequest($_GET, $_POST, $_COOKIE, $_FILES, $_SERVER);
            } catch (\Throwable $e) {
                $this->exceptionHandler->handleException($e);
            }
            $this->app->terminate();
            return;
        }

        $this->installSignalHandlers();

        // Worker-loop: данные запроса приходят от адаптера, не из суперглобалов.
        $handler = function (
            array $get, array $post, array $cookie, array $files, array $server, string $rawBody,
        ): void {
            try {
                $this->app->handleRequest($get, $post, $cookie, $files, $server, $rawBody);
            } catch (\Throwable $e) {
                $this->exceptionHandler->handleException($e);
            }
        };

        for ($n = 0; !$this->maxRequests || $n < $this->maxRequests; ++$n) {
            $keepRunning = $loop($handler);

            $this->app->terminate();   // хук: закрытие ресурсов (сейчас пустой)
            gc_collect_cycles();

            if (!$keepRunning || $this->shouldShutdown) break;
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
| `LOG_FILE` | — | Путь к файлу лога (активирует второй JsonStreamLogger) |
| `JWT_SECRET` | обязателен | HMAC-ключ для JWT (≥ 32 байт) |
