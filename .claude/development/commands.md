# Commands Reference

## Запуск worker

FrankenPHP стартует PHP-воркеры через Caddyfile:

```bash
frankenphp run --config Caddyfile
```

Воркер-скрипт приложения подключается к фреймворку через `WorkerRunner`:

```php
// worker.php
$runner = new WorkerRunner($kernel);
$runner->run();
```

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
