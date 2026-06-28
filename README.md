# Agent-Ready Installer

Laravel Composer package that installs Agent-Ready local-development tooling into an application.

The installer generates project-local Docker wrappers, runtime Compose files, worktree helpers, and optional agent guidance. It is Docker-first and does not require Laravel Sail, host PHP, or host Composer after the generated tooling is installed.

## Status

This repository is pre-release V1 work. The public command surface is intentionally small:

```bash
php artisan agent-ready:install
```

The installer writes files and local state only. It does not provision Docker resources during installation. The first `./local up` starts shared companions, provisions checkout databases/cache namespaces, starts runtime containers, and materializes runtime env files.

## Requirements

- Laravel 12 or 13 application.
- Docker with Docker Compose v2.
- A shared Traefik companion checkout.
- A shared services companion checkout.

By default, the installer looks for these sibling directories next to the application checkout:

```text
../shared-traefik
../shared-services
```

Pass explicit paths when they live elsewhere:

```bash
php artisan agent-ready:install \
  --traefik-home=/home/edgar/projects/shared-traefik \
  --services-home=/home/edgar/projects/shared-services
```

## Installation

Install the package as a development dependency in the Laravel application:

```bash
composer require --dev agents-ready/laravel-installer
```

Run the installer:

```bash
php artisan agent-ready:install
```

For non-interactive installs, pass `--host-root` and any companion paths that cannot be discovered:

```bash
php artisan agent-ready:install \
  --host-root=/absolute/path/to/app \
  --traefik-home=/absolute/path/to/shared-traefik \
  --services-home=/absolute/path/to/shared-services
```

Start the local stack:

```bash
./local up
```

## Options

Runtime options:

```bash
php artisan agent-ready:install --runtime=frankenphp
php artisan agent-ready:install --runtime=nginx-php-fpm
```

Database options:

```bash
php artisan agent-ready:install --database=pgsql
php artisan agent-ready:install --database=mysql
```

Optional Horizon service:

```bash
php artisan agent-ready:install --horizon
```

Optional project conventions:

```bash
php artisan agent-ready:install --decision-notes
php artisan agent-ready:install --local-agent-notes
php artisan agent-ready:install --agent-skills
```

Existing project candidate mode:

```bash
php artisan agent-ready:install --existing-project-candidates
```

Candidate mode writes only `worktree.docker-compose.yml` for review/manual integration. It does not mutate existing `.env`, `.env.testing`, Compose, Sail, Docker, wrapper, or agent files.

## Generated Workflow

Common commands after installation:

```bash
./local up
./local down
./local status
./local artisan migrate
./local test
./local composer install
./local shell
./local assets dev
./local assets build
./local worktree list
./local worktree create <branch>
./local worktree remove <worktree>
```

Vite is explicit: `./local up` does not start Vite. Use `./local assets dev` when needed.

`./local sail ...` is a compatibility alias for the generated wrapper. After `./local up`, direct `docker compose ...` commands also work for compatible workflows.

## Identity And Env

Agent-Ready identifies checkouts by `projectId + worktreeId` in `.agent-ready/worktree.json`.

Runtime `.env` files contain app/runtime compatibility values such as `APP_URL`, `DB_*`, `REDIS_*`, `COMPOSE_PROJECT_NAME`, and `APP_SERVICE`. Agent-Ready identity is intentionally not written into `.env`.

## Development

This package is developed with Docker-first tooling:

```bash
make install
make format-test
make test
make analyse
```

Opt-in smoke checks are available:

```bash
make smoke-install
SMOKE_PROFILE=runtime SMOKE_RUNTIME=nginx-php-fpm SMOKE_DATABASE=mysql make smoke
```

Smoke workspaces default to `/tmp/opencode/agent-ready-installer-smoke` and clean recorded resources on successful runs.
