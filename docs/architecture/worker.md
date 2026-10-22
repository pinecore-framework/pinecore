# Worker и запуск

## Entrypoint (`worker.php` приложения)

Фреймворк не привязан к конкретному рантайму. У `WorkerRunner::run()` два режима:

**1. Single-request (без аргументов).** Один запрос и выход — классический SAPI/FPM/CGI. PHP runtime сам перенаполняет `$_GET, $_POST, $_COOKIE, $_FILES, $_SERVER` и `php://input` под каждый запрос, поэтому `WorkerRunner` читает их напрямую.

```php
$container = Kernel::boot(__DIR__);
$runner    = $container->get(WorkerRunner::class);
$runner->run();
```

**2. Worker-loop (с адаптером).** Долгоживущий процесс под RoadRunner / FrankenPHP / ReactPHP / Swoole / Amphp. В этих рантаймах суперглобалы и `php://input` фиксируются на старте процесса и **не обновляются между итерациями**, поэтому `WorkerRunner` их в этом режиме не трогает: данные текущего запроса передаёт адаптер.

```php
$container = Kernel::boot(__DIR__);
$runner    = $container->get(WorkerRunner::class);

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

Адаптер получает `callable $handler` с сигнатурой `(array $get, array $post, array $cookie, array $files, array $server, string $rawBody): void` и возвращает `bool` — продолжать ли цикл. Сам цикл (лимит итераций, `gc_collect_cycles()`, сигналы, graceful shutdown) живёт в `ProcessLoop`; `WorkerRunner` поверх него вызывает `$app->terminate()` на каждой итерации. `WorkerRunner` и `ProcessLoop` резолвятся через DI в `ContainerFactory`.

## ProcessLoop (`src/Worker/ProcessLoop.php`)

Скелет долгоживущего воркера, **не привязанный к HTTP**. Владеет всем, что нужно любому типу воркера: pcntl-сигналы → graceful shutdown, лимит `maxRequests`, `gc_collect_cycles()` между итерациями и общий флаг `WorkerLifecycle` (через него же репозитории ресайклят воркер при потере БД-коннекта).

```php
final class ProcessLoop
{
    public function __construct(
        private readonly WorkerLifecycle $lifecycle,
        private readonly int $maxRequests = 0,   // 0 = без лимита
    ) {}

    /** @param callable():bool $tick единица работы; false — остановить цикл */
    public function run(callable $tick): void
    {
        $this->installSignalHandlers();

        for ($n = 0; !$this->maxRequests || $n < $this->maxRequests; ++$n) {
            $keepRunning = $tick();
            gc_collect_cycles();

            if ($keepRunning === false || $this->lifecycle->shouldShutdown()) break;
        }
    }
}
```

`$tick` сам отвечает за свои ошибки — неперехваченное исключение выходит из цикла и завершает воркер (супервизор перезапустит; это и есть штатное восстановление, а не баг).

### WorkerRunner — HTTP-потребитель лупа

`WorkerRunner` (`src/Http/WorkerRunner.php`) адаптирует данные рантайма к `HttpApplication::handleRequest` и отдаёт цикл `ProcessLoop`:

```php
$this->loop->run(function () use ($adapter, $handler): bool {
    $keepRunning = $adapter($handler);   // адаптер тянет один запрос → $handler
    $this->app->terminate();             // HTTP-специфичная очистка
    return (bool) $keepRunning;
});
```

Single-request режим (`run()` без аргументов) в `ProcessLoop` не заходит — это чисто HTTP/SAPI-путь.

### Другие типы воркеров

Консьюмер очереди и scheduler-tick садятся на тот же `ProcessLoop` и **бесплатно** получают graceful shutdown и recycle при потере БД — отличается только tick:

```php
$loop = $container->get(ProcessLoop::class);

$loop->run(function () use ($queue, $handler): bool {
    $message = $queue->pop();          // блокирующее чтение или null
    if ($message !== null) {
        $handler($message);            // обработка; репозитории внутри ресайклят воркер при обрыве БД
    }
    return true;                        // продолжать потреблять
});
```

## Graceful shutdown

В worker-loop режиме `ProcessLoop` устанавливает обработчики `SIGTERM` и `SIGINT` (`pcntl_async_signals(true)`). При получении сигнала:

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

Флаг остановки вынесен в общий сервис `WorkerLifecycle` (DI-синглтон, без зависимостей): его читает `ProcessLoop` и его же дёргают обработчики сигналов (а `WorkerRunner::requestShutdown()` делегирует туда же). Тот же синглтон инжектится в репозитории и `SqlHealthCheck` — при оборванном соединении с БД (`PDOException`/`ConnectionException`) они вызывают `requestShutdown()`, и воркер ресайклится с живым коннектом. Параметр обязательный: nullable параметр PHP-DI при автовайринге пропускает, и guard незаметно отключается. Сервисы с голым `PDO` в конструкторе guard обходят. Детали — [`orm-sql.md`](orm-sql.md#оборванное-соединение-worker-recycle) и [`orm.md`](orm.md).

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
