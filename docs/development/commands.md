# Commands Reference

## Запуск worker

Фреймворк запускается удобным для пользователя способом — способ запуска выбирает само приложение. Воркер-скрипт приложения подключается к фреймворку через `WorkerRunner`:

```php
// worker.php — один запрос (классический SAPI)
$runner->run();

// worker.php — worker-петля, адаптер инжектится приложением
$runner->run(fn ($handler) => /* runtime-specific request loop */);
```

Адаптер получает `callable $handler` и возвращает `bool` (продолжать ли цикл). `MAX_REQUESTS`, `terminate()` и `gc_collect_cycles()` `WorkerRunner` делает сам между итерациями.

---

## Console-команды

```bash
php bin/console <command> [options]
```

Команды регистрируются в контейнере как сервисы с тегом (см. `architecture/console.md`).

---

## Composer

На хосте PHP не установлен — composer запускается через docker-compose:

```bash
docker compose run --rm --no-deps php composer install
docker compose run --rm --no-deps php composer update
docker compose run --rm --no-deps php composer dump-autoload
```

---

## Tests

```bash
docker compose run --rm --no-deps php vendor/bin/phpunit
docker compose run --rm --no-deps php vendor/bin/phpunit --testdox
docker compose run --rm --no-deps php vendor/bin/phpunit --filter MongoHydratorTest
```

PHPUnit 11, конфиг — `phpunit.xml` в корне. Тесты лежат в `tests/`, фикстуры — в `tests/Fixtures/`.

---

## Docker

```bash
docker compose build php   # Пересобрать PHP CLI образ
docker compose run --rm --no-deps php sh   # Шелл в контейнере
```

Образ описан в `.docker/php/Dockerfile` (PHP 8.2-cli-alpine + ext-mongodb + composer). HTTP-сервиса в compose нет — только CLI для composer/phpunit.
