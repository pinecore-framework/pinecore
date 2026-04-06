# Code Patterns

Переиспользуемые паттерны кодовой базы. Используй существующие — не изобретай новые без необходимости.

---

## 1. Kernel — статический контейнер

```php
// Инициализация (один раз при старте воркера)
Kernel::init($container);

// Получение сервиса
$service = Kernel::getContainer()->get(MyService::class);
```

Kernel хранит единственный инстанс контейнера на весь процесс воркера.

---

## 2. ContainerFactory — сборка контейнера

```php
$factory = new ContainerFactory($config, $environment);
$container = $factory->build();
```

Autoload: все файлы из `routes/` и `services/` подхватываются автоматически.
Не регистрируй маршруты и сервисы вручную в `index.php` — кладёт файлы в соответствующие директории.

---

## 3. Router и RouteDefinition

```php
// routes/api.php
return [
    new RouteDefinition('GET', '/users/{id}', UserController::class, 'show'),
];
```

Router находит нужный маршрут по методу и пути, извлекает параметры и вызывает контроллер.

---

## 4. Middleware — цепочка обработки запроса

```php
class MyMiddleware implements MiddlewareInterface {
    public function handle(Request $request, callable $next): Response {
        // до
        $response = $next($request);
        // после
        return $response;
    }
}
```

Middleware регистрируются в контейнере и применяются ко всем запросам в воркере.

---

## 5. ORM — атрибуты сущностей

```php
#[Collection('users')]
class UserEntity {
    #[Field('_id')]
    public string $id;

    #[Field('name')]
    public string $name;
}
```

Repository наследует базовый класс и получает методы `find`, `findOne`, `save`, `delete` из коробки.

---

## 6. JWT Auth

```php
// AuthMiddleware автоматически валидирует Bearer-токен
// В контроллере — получить текущего пользователя из request
$user = $request->getAttribute('user');
```

`JwtService` отвечает за выпуск и верификацию токенов. Конфиг: секрет и TTL через `Config`.

---

## 7. Config — конфигурация

```php
$value = Config::get('key');
$value = Config::get('section.key', $default);
```

Конфиг загружается из `environment.php` (или `.env`). Доступен глобально через статический метод.

---

## 8. WorkerRunner — цикл обработки запросов

```php
// Внутри worker.php
$runner = new WorkerRunner($kernel);
$runner->run(); // FrankenPHP loop: запрос → роутинг → middleware → контроллер → ответ
```

`WorkerRunner` перехватывает исключения и возвращает корректный HTTP-ответ даже при ошибке.
