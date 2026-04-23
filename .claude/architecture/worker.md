# Worker и запуск

## Entrypoint (`worker.php` приложения)

Фреймворк запускается удобным для пользователя способом. `WorkerRunner::run()` без аргументов обрабатывает один запрос и завершается (классический SAPI/FPM/CGI). Чтобы крутить worker-петлю, приложение передаёт свой адаптер:

```php
use Pronchev\Pinecore\Kernel;
use Pronchev\Pinecore\Http\WorkerRunner;

require __DIR__ . '/vendor/autoload.php';

Kernel::boot(__DIR__);
$runner = Kernel::container()->get(WorkerRunner::class);

// Один запрос:
$runner->run();

// Или worker-петля (пример для FrankenPHP):
$runner->run(fn ($handler) => frankenphp_handle_request($handler));
```

Адаптер получает `callable $handler` (обработать один запрос) и возвращает `bool` — продолжать ли цикл. `MAX_REQUESTS`, `$app->terminate()` и `gc_collect_cycles()` отрабатывает сам `WorkerRunner` между итерациями. `WorkerRunner` резолвится через DI autowiring — конфигурировать не нужно.

## WorkerRunner (`src/Http/WorkerRunner.php`)

```php
final class WorkerRunner
{
    public function __construct(
        private readonly HttpApplication  $app,
        private readonly ExceptionHandler $exceptionHandler,
    ) {}

    public function run(?callable $loop = null): void
    {
        if ($loop === null) {
            $this->handle();
            $this->app->terminate();
            return;
        }

        $handler = fn () => $this->handle();
        $maxRequests = (int) ($_SERVER['MAX_REQUESTS'] ?? 0);  // 0 = без лимита

        for ($n = 0; !$maxRequests || $n < $maxRequests; ++$n) {
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

## Env-переменные

| Переменная | По умолчанию | Описание |
|---|---|---|
| `APP_ENV` | `dev` | Среда исполнения |
| `MAX_REQUESTS` | `0` | Лимит запросов на воркер (0 = без лимита) |
| `LOG_LEVEL` | `debug` | Минимальный уровень логирования |
| `LOG_FILE` | — | Путь к файлу лога (активирует FileLogger) |
| `JWT_SECRET` | обязателен | HMAC-ключ для JWT |
