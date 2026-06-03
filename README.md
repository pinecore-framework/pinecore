# 🌲 Pinecore

Минимальный PHP-фреймворк для долгоживущих воркеров. Бутстрап один раз — обслуживание многих запросов в цикле, без привязки к конкретному рантайму.

```php
$container = Kernel::boot(__DIR__);
$container->get(WorkerRunner::class)->run();   // SAPI: один запрос
```

Тот же код под RoadRunner / FrankenPHP / Swoole — добавляется только адаптер цикла (см. [Worker](#worker)).

## Подходы

- **Worker-first.** `Kernel::boot()` собирает Config и DI-контейнер один раз; в prod контейнер компилируется. Дальше — горячий путь без пересборки. Между итерациями — `gc_collect_cycles()` и хук `terminate()`.
- **Runtime-agnostic.** `WorkerRunner` не знает про SAPI/RoadRunner/Swoole. Данные запроса приходят либо из суперглобалов (SAPI), либо от адаптера (worker-loop). Graceful shutdown по `SIGTERM`/`SIGINT` — забота фреймворка, форсированный таймаут — оркестратора.
- **Всё через DI.** Контроллеры, middleware, репозитории, console-команды — invokable-классы, резолвятся автовайрингом (PHP-DI). Ручной биндинг — только для интерфейсов.
- **Конфиг — это PHP-файлы.** `routes.php`, `commands.php`, `services.php`, `orm.php` возвращают обычные массивы/замыкания; фреймворк сам собирает из них Router, ConsoleRouter и DI-определения. Никаких аннотаций-роутинга и сканирования каталогов.
- **Fail-fast, без магии.** Иммутабельный `Request`, типизированное чтение конфига (`requireInt` вместо `(int) '8mb'`), валидация ORM-атрибутов до первой хидрации, обязательный CORS-allowlist. Ошибка конфигурации валит boot, а не всплывает в проде.

## Установка

```bash
composer require pinecore/pinecore
```

PHP 8.2+. Слой персистентности опционален — ядро не тянет ни одну БД как `require`, бэкенд подключается по выбору:

- **MongoDB ORM** — `composer require mongodb/mongodb` (+ расширение `ext-mongodb`);
- **SQL / PostgreSQL ORM (pgvector)** — расширения `ext-pdo` + `ext-pdo_pgsql`, composer-пакетов не нужно.

Опционально `pcntl` — graceful shutdown воркера.

## Bootstrap

```php
// public/worker.php
use Pinecore\Pinecore\Kernel;
use Pinecore\Pinecore\Http\WorkerRunner;

require __DIR__ . '/../vendor/autoload.php';

$container = Kernel::boot(__DIR__ . '/..');
$container->get(WorkerRunner::class)->run();
```

`Kernel::boot()` делает три шага: `Environment::detect()` (по `APP_ENV`, дефолт `dev`) → `Config::load()` → `ContainerFactory::build()`. Конфиг слоится: `config/*.php`, поверх — `config/env/{env}.php`, поверх — `config/env/local.php`.

```php
// config/routes.php
use Pinecore\Pinecore\Http\RouteDefinition;
use Pinecore\Pinecore\Auth\AuthMiddleware;

return [
    new RouteDefinition('GET',  '/users/{id}', GetUserController::class),
    new RouteDefinition('POST', '/users',      CreateUserController::class, [AuthMiddleware::class]),
];
```

`ContainerFactory` подхватывает `routes.php`, `commands.php`, `orm.php` по соглашению и собирает соответствующие компоненты. `config/services.php` — для DI-биндингов, грузится последним и может переопределить что угодно:

```php
// config/services.php
return function (ContainerBuilder $builder, Config $config, string $basePath): void {
    $builder->addDefinitions([
        UserProviderInterface::class => fn($c) => $c->get(UserRepository::class),
    ]);
};
```

## HTTP

Контроллер — invokable-класс, принимает `Request`, возвращает `Response`:

```php
final class GetUserController
{
    public function __construct(private UserRepository $users) {}   // DI

    public function __invoke(Request $request): Response
    {
        $user = $this->users->findById($request->pathParams['id'])
            ?? throw new HttpException('Not found', 404);

        return Response::json(['id' => $user->id, 'email' => $user->email]);
    }
}
```

`Request` иммутабелен; тело парсится по `Content-Type` (JSON / form-urlencoded / multipart), битый JSON → `HttpException(400)` ещё до контроллера. Middleware — цепочка, каждый получает и возвращает `Request`, общаясь с остальными через context (так устроен и встроенный `AuthMiddleware`):

```php
final class AuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request): Request
    {
        $userId = $this->jwt->verify($this->bearer($request));   // бросит AuthException → 401
        return $request->withContext('auth', new AuthContext($this->users->findById($userId)));
    }
}
```

CORS (`app.cors`), лимит размера тела (`http.max_body_bytes`, дефолт 8 MiB) и преобразование исключений в ответы (`HttpException` → код, `\Throwable` → 500 + лог) встроены в `HttpApplication`. → [docs/architecture/http.md](docs/architecture/http.md)

## Config

Dot-notation плюс типизированное чтение с fail-fast — никаких тихих кастов:

```php
$config->requireString('jwt.secret');           // нет ключа или не string → RuntimeException
$config->getInt('http.max_body_bytes', 8 << 20); // дефолт, но если есть и не int → RuntimeException
$config->requireStringList('cors.allowed_origins');
$config->get('section.key', $default);           // escape hatch, mixed
```

## Worker

`WorkerRunner::run()` без аргументов — single-request (FPM/CGI). С замыканием-адаптером — долгоживущий цикл; адаптер ходит в рантайм за запросом и передаёт его данные в `$handler`:

```php
$runner->run(function ($handler) use ($psr7Worker) {
    $req = $psr7Worker->waitRequest();
    if ($req === null) return false;             // воркер просят выйти

    $handler(
        $req->getQuery(), $req->getPost(), $req->getCookies(),
        $req->getUploads(), $req->getServer(), $req->getBody(),
    );
    return true;                                  // продолжать цикл
});
```

Скелет цикла — лимит итераций (`worker.max_requests`, 0 = без лимита), `gc_collect_cycles()`, graceful shutdown по сигналам и `requestShutdown()` — в runtime-нейтральном `ProcessLoop`; `WorkerRunner` — его HTTP-потребитель (добавляет `terminate()`). На том же `ProcessLoop` едут не-HTTP воркеры (очередь, scheduler). → [docs/architecture/worker.md](docs/architecture/worker.md)

## ORM (MongoDB)

Сущности — иммутабельные, маппинг через атрибуты на параметрах конструктора:

```php
#[Collection(name: 'users')]
final class User implements MongoEntity
{
    public function __construct(
        #[Id]    public readonly string  $id,
        #[Field] #[Index(unique: true)] public readonly string $email,
        #[Field] public readonly array   $roles = [],   // BSONArray ↔ array прозрачно
    ) {}
}

#[ForEntity(User::class)]
final class UserRepository extends AbstractMongoRepository
{
    public function findByEmail(string $email): ?User
    {
        return $this->findOneWhere(['email' => $email]);   // типизировано через #[ForEntity]
    }
}
```

`persist()` делает upsert по полю `id` (пустой `id` → генерация нового). Поля с дефолтами позволяют эволюционировать схему без миграций. Индексы из `#[Index]` раскатываются командой `php bin/console mongo:ensure-indexes` (идемпотентна, additive-only). → [docs/architecture/orm.md](docs/architecture/orm.md)

## Auth

JWT (HS256, lcobucci/jwt). `AuthMiddleware` валидирует `Bearer`-токен, грузит пользователя через `UserProviderInterface` и кладёт `AuthContext` в request:

```php
$auth = $request->get('auth');   // AuthContext, выставленный AuthMiddleware
$user = $auth->user;
```

→ [docs/architecture/auth.md](docs/architecture/auth.md)

## Console

Команды объявляются как `routes.php` — списком определений; хендлер invokable, резолвится через DI:

```php
// config/commands.php
return [
    new ConsoleDefinition('users:create {role}', CreateUserCommand::class, 'Create a user', [
        new OptionDefinition('email',   'User email',  null),    // опция (string|null)
        new OptionDefinition('dry-run', 'Do not persist', false), // флаг (bool)
    ]),
];

final class CreateUserCommand
{
    public function __invoke(ConsoleInput $input, ConsoleOutput $output): void
    {
        $role = $input->pathParams['role'];
        if (!$input->option('dry-run')) { /* ... */ }
        $output->success("created {$role}");
    }
}
```

Вывод — обёртка над `league/climate` (цвета, прогресс-бары, таблицы). Опции и позиционные аргументы можно перемешивать. → [docs/architecture/console.md](docs/architecture/console.md)

## Logging

PSR-3, структурированный JSON в любой stream (по умолчанию `php://stdout`):

```php
$this->logger->error('Payment failed', ['exception' => $e, 'order' => $orderId]);
// {"ts":"…","level":"error","channel":"app","message":"Payment failed","context":{…}}
```

`Throwable` в context разворачивается в `{class, message, file, line, trace}`; логгер не бросает исключений наружу. Файл-бэкенд через `log.file`, кастомный — переопределением `LoggerInterface` в `services.php`. → [docs/architecture/logging.md](docs/architecture/logging.md)

## Документация

Полный индекс базы знаний — [docs/README.md](docs/README.md): архитектура и жизненный цикл запроса, bootstrap, HTTP, worker, auth, ORM, console, logging, ADR.

## Лицензия

MIT
