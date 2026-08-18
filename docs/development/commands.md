# Commands Reference

## Запуск worker

Фреймворк запускается удобным для пользователя способом — способ запуска выбирает само приложение. Воркер-скрипт приложения подключается к фреймворку через `WorkerRunner`:

```php
// worker.php — один запрос (классический SAPI)
$runner->run();

// worker.php — worker-петля, адаптер инжектится приложением
$runner->run(fn ($handler) => /* runtime-specific request loop */);
```

Адаптер получает `callable $handler` и возвращает `bool` (продолжать ли цикл). Сам цикл — `max_requests`, `gc_collect_cycles()`, сигналы, graceful shutdown — в `ProcessLoop`; `WorkerRunner` поверх него делает `terminate()` на каждой итерации. Тот же `ProcessLoop` переиспользуют не-HTTP воркеры (очередь, scheduler).

---

## Console-команды

```bash
php bin/console <command> [options]
```

Команды регистрируются в контейнере как сервисы с тегом (см. `architecture/console.md`).

Команды, поставляемые фреймворком (приложение регистрирует их в своём console-конфиге):

| Команда | Описание |
|---|---|
| `pg:migrate [--dry-run]` | Применить SQL-миграции из `orm.migrations_path` (см. `architecture/migrations.md`) |
| `pg:ensure-indexes [--dry-run]` | Раскатать индексы PostgreSQL из атрибутов сущностей |
| `mongo:ensure-indexes` | Раскатать индексы MongoDB из атрибутов сущностей |

Порядок деплоя: `pg:migrate` → `pg:ensure-indexes`.

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
