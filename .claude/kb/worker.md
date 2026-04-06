# Worker и запуск

## Entrypoint (`worker.php` приложения)

FrankenPHP запускается как `frankenphp run --config Caddyfile` и сам стартует PHP-воркеры.

```php
use Pronchev\Pinecore\Kernel;
use Pronchev\Pinecore\Http\WorkerRunner;

require __DIR__ . '/vendor/autoload.php';

Kernel::boot(__DIR__);
Kernel::container()->get(WorkerRunner::class)->run();
```

`WorkerRunner` резолвится через DI autowiring — конфигурировать не нужно.

## WorkerRunner (`src/Http/WorkerRunner.php`)

```php
final class WorkerRunner
{
    public function __construct(
        private readonly HttpApplication  $app,
        private readonly ExceptionHandler $exceptionHandler,
    ) {}

    public function run(): void
    {
        $maxRequests = (int) ($_SERVER['MAX_REQUESTS'] ?? 0);  // 0 = без лимита

        for ($n = 0; !$maxRequests || $n < $maxRequests; ++$n) {
            $keepRunning = frankenphp_handle_request(function (): void {
                try {
                    $this->app->handleRequest($_GET, $_POST, $_COOKIE, $_FILES, $_SERVER);
                } catch (\Throwable $e) {
                    $this->exceptionHandler->handleException($e);  // critical лог
                }
            });

            $this->app->terminate();   // хук: закрытие ресурсов (сейчас пустой)
            gc_collect_cycles();

            if (!$keepRunning) break;  // FrankenPHP сигнализирует об остановке
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
