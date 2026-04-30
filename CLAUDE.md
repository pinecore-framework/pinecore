# CLAUDE.md

Guidance for Claude Code when working with this repository.

## Package

`pronchev/pinecore` — minimal PHP framework for long-running workers.

**Namespace:** `Pronchev\Pinecore\` → `src/` (PSR-4)

## Tests

PHP на хосте не установлен — всё гоняется через docker-compose:

```bash
docker compose run --rm --no-deps php vendor/bin/phpunit
```

PHPUnit 11. Конфиг — `phpunit.xml`. Тесты — `tests/`, фикстуры — `tests/Fixtures/`.

## Knowledge base

Full index: [.claude/README.md](.claude/README.md)

- [Architecture & request lifecycle](.claude/architecture/overview.md)
- [Bootstrap: Kernel, Config, Environment, ContainerFactory](.claude/architecture/bootstrap.md)
- [HTTP layer: Request, Response, Router, Middleware](.claude/architecture/http.md)
- [Worker entrypoint & WorkerRunner](.claude/architecture/worker.md)
- [Auth: JWT, AuthMiddleware](.claude/architecture/auth.md)
- [Logging](.claude/architecture/logging.md)
- [ORM: MongoDB, entities, repositories](.claude/architecture/orm.md)
- [Console](.claude/architecture/console.md)

## Code Style

EditorConfig enforces:
- PHP: UTF-8, LF, 4-space indent, 120-char line limit (PSR-12)
- JS/TS: 2-space indent, 100-char line limit
- Templates (Blade/Twig), YAML, JSON, Docker: 2-space indent
