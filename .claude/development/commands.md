# Commands Reference

## Запуск worker

Фреймворк запускается удобным для пользователя способом — способ запуска выбирает само приложение. Воркер-скрипт приложения подключается к фреймворку через `WorkerRunner`:

```php
// worker.php — один запрос (классический SAPI)
$runner->run();

// worker.php — worker-петля, адаптер инжектится приложением
$runner->run(fn ($handler) => frankenphp_handle_request($handler));
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

```bash
composer install          # Установить зависимости из lock
composer update           # Обновить зависимости
composer dump-autoload    # Пересоздать autoloader
```

---

## Docker (если используется в приложении)

```bash
docker compose up -d      # Запустить сервисы
docker compose build      # Пересобрать образ
docker compose logs -f    # Логи в реальном времени
```

---

## Git shortcuts

```bash
# Создать task-файл для новой ветки
cp .claude/tasks/_template.md .claude/tasks/active/<branch-name>.md
```
