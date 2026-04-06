# CLAUDE.md

Guidance for Claude Code when working with this repository.

## Package

`pronchev/pinecore` — minimal PHP framework for FrankenPHP long-running workers.

**Namespace:** `Pronchev\Pinecore\` → `src/` (PSR-4)

No lint or test commands configured yet.

## Knowledge base

Detailed implementation docs: [.claude/INDEX.md](.claude/INDEX.md)

- [Architecture & request lifecycle](.claude/kb/architecture.md)
- [Bootstrap: Kernel, Config, Environment, ContainerFactory](.claude/kb/bootstrap.md)
- [HTTP layer: Request, Response, Router, Middleware](.claude/kb/http.md)
- [Worker entrypoint & WorkerRunner](.claude/kb/worker.md)
- [Auth: JWT, AuthMiddleware](.claude/kb/auth.md)
- [Logging](.claude/kb/logging.md)
- [ORM: MongoDB, entities, repositories](.claude/kb/orm.md)
- [Console](.claude/kb/console.md)

## Code Style

EditorConfig enforces:
- PHP: UTF-8, LF, 4-space indent, 120-char line limit (PSR-12)
- JS/TS: 2-space indent, 100-char line limit
- Templates (Blade/Twig), YAML, JSON, Docker: 2-space indent
