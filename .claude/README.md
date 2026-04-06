# Knowledge Base Index

Полный индекс базы знаний проекта Pinecore. Все файлы в этой директории коммитятся в репозиторий.

## Быстрая навигация

| Что нужно | Файл |
|-----------|------|
| Я только что открыл проект | [`architecture/overview.md`](architecture/overview.md) |
| Как поднять worker / запустить? | [`development/commands.md`](development/commands.md) |
| Какой паттерн использовать? | [`development/patterns.md`](development/patterns.md) |
| Commit/branch/namespace conventions | [`development/conventions.md`](development/conventions.md) |
| Как отладить локально | [`development/testing.md`](development/testing.md) |
| Ядро, Config, Environment, ContainerFactory | [`architecture/bootstrap.md`](architecture/bootstrap.md) |
| HTTP: Request, Response, Router, Middleware | [`architecture/http.md`](architecture/http.md) |
| Worker и WorkerRunner | [`architecture/worker.md`](architecture/worker.md) |
| Auth (JWT, AuthMiddleware) | [`architecture/auth.md`](architecture/auth.md) |
| Логирование | [`architecture/logging.md`](architecture/logging.md) |
| ORM (MongoDB) | [`architecture/orm.md`](architecture/orm.md) |
| Console-команды | [`architecture/console.md`](architecture/console.md) |
| Почему архитектура именно такая | [`decisions/README.md`](decisions/README.md) |
| Контекст текущей задачи | [`tasks/active/`](tasks/active/) |

## Структура директории

```
.claude/
├── README.md                    # Этот файл — индекс
├── architecture/                # Стабильные архитектурные docs
│   ├── overview.md              # Обзор системы и жизненный цикл запроса
│   ├── bootstrap.md             # Kernel, Config, Environment, ContainerFactory
│   ├── http.md                  # HTTP слой: Request, Response, Router, Middleware
│   ├── worker.md                # Worker entrypoint и WorkerRunner
│   ├── auth.md                  # Auth: JWT, AuthMiddleware
│   ├── logging.md               # Логирование
│   ├── orm.md                   # ORM: MongoDB, entities, repositories
│   └── console.md               # Console-команды
├── development/                 # Практика разработки
│   ├── patterns.md              # Переиспользуемые code patterns
│   ├── conventions.md           # Соглашения: коммиты, ветки, неймспейсы
│   ├── commands.md              # Команды запуска и утилиты
│   └── testing.md               # Отладка и тестирование
├── decisions/                   # Architecture Decision Records (ADR)
│   ├── README.md                # Индекс ADR + как писать
│   └── ADR-NNN-*.md
└── tasks/                       # Контекст задач (по одному файлу на ветку)
    ├── _template.md             # Шаблон для новой задачи
    ├── active/                  # Активные ветки
    └── completed/               # Смерженные задачи
```

## Правила обновления

**Во время разработки в feature-ветке:**
- Пиши только в `tasks/active/<branch-name>.md` (свой файл)
- Можешь добавить `decisions/ADR-NNN.md` (новый файл — нет конфликтов)
- НЕ трогай `architecture/` и `development/` — только в main

**После мержа в main (интегратор):**
- Читает секцию "Merge Notes" в task-файле
- При необходимости обновляет `architecture/` и `development/`
- Перемещает: `tasks/active/<branch>.md` → `tasks/completed/<branch>.md`
