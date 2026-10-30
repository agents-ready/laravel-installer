# Agents-Ready Installer

Laravel Composer package that installs Agents-Ready local-development tooling into an application.

The installer generates project-local Docker wrappers, runtime Compose files, worktree helpers, and optional agent guidance. It is Docker-first and does not require Laravel Sail, host PHP, or host Composer after the generated tooling is installed.

## Status

This repository is pre-release V1 work. The public command surface is intentionally small:

```bash
php artisan agents-ready:install
php artisan agents-ready:conventions
```

The installer writes core tooling files and local state only. It does not provision Docker resources during installation. The first `./local up` starts shared companions, provisions checkout databases/cache namespaces, starts runtime containers, and materializes runtime env files.

Optional project/team conventions are intentionally separate from core installation. Use `agents-ready:conventions` for files such as local agent notes, tracked skill placement, activation-link helpers, and decision-note templates.

## Requirements

- Laravel 12 or 13 application.
- Docker with Docker Compose v2.
- A shared Traefik companion checkout.
- A shared services companion checkout.
- `mkcert` installed on the host for trusted local HTTPS certificates.

Most projects keep the companion checkouts next to the application checkout:

```text
../shared-traefik
../shared-services
```

The installer always requires explicit host paths for the app checkout and both companions. This keeps Docker-based installs unambiguous and makes the generated local config reproducible.

## First-Time Checklist

1. Start Docker.
2. Install and initialize `mkcert` on the host.
3. Clone `shared-traefik` and `shared-services` next to the app checkout.
4. Install `agents-ready/laravel-installer` in the Laravel app.
5. Run `php artisan agents-ready:install` with the three required host paths.
6. Optionally run `php artisan agents-ready:conventions` for project/team agent conventions.
7. Run `./local up --wait`.

## Local HTTPS Certificates

Agents-Ready routes app URLs through shared Traefik over HTTPS, for example `https://real-agent-app.localhost`. For browser-trusted certificates, install `mkcert` and run `mkcert -install` once on the host before the first `./local up`.

macOS with Homebrew:

```bash
brew install mkcert nss
mkcert -install
```

Ubuntu/Debian:

```bash
sudo apt install libnss3-tools mkcert
mkcert -install
```

Windows is WSL2-first for now. Install and initialize `mkcert` inside WSL for Linux CLI tools and Linux browsers. If browsing from Windows Chrome or Edge, also install/run `mkcert -install` on Windows so the Windows trust store knows the local CA.

If `mkcert` is missing, shared Traefik falls back to a self-signed certificate. CLI checks can still use `curl -k`, but browsers will show certificate warnings.

## Quick Start

Create a workspace and clone the shared companions:

```bash
mkdir -p ~/projects/agents-ready-real-test
cd ~/projects/agents-ready-real-test

git clone https://github.com/agents-ready/shared-traefik.git
git clone https://github.com/agents-ready/shared-services.git
```

Create a Laravel app with host Composer:

```bash
composer create-project laravel/laravel real-agent-app "^13.0"
cd real-agent-app
```

Install Agents-Ready:

```bash
composer require --dev agents-ready/laravel-installer:^0.2

php artisan agents-ready:install \
  --host-root="$(pwd)" \
  --traefik-home="$(cd ../shared-traefik && pwd)" \
  --services-home="$(cd ../shared-services && pwd)"
```

The install command is interactive by default. It will ask for project slug, runtime, and database. Optional project/team conventions are installed separately.

Optionally add conventions:

```bash
php artisan agents-ready:conventions
```

Start the stack:

```bash
./local up --wait
```

Verify:

```bash
./local status
./local artisan about
./local test
curl -kI https://real-agent-app.localhost
```

Use `curl -kI` for CLI checks because it works even before the local CA is trusted by that shell environment. Browsers should trust the certificate after `mkcert -install`.

## No Host Composer

If the host has Docker but no PHP or Composer, use the Composer Docker image until `./local` exists.

Create the Laravel app:

```bash
mkdir -p ~/projects/agents-ready-real-test
cd ~/projects/agents-ready-real-test

git clone https://github.com/agents-ready/shared-traefik.git
git clone https://github.com/agents-ready/shared-services.git

docker run --rm -it \
  -u "$(id -u):$(id -g)" \
  -v "$PWD":/workspace \
  -w /workspace \
  composer:2.9.2 \
  create-project laravel/laravel real-agent-app "^13.0"

cd real-agent-app
```

Require the installer:

```bash
docker run --rm -it \
  -u "$(id -u):$(id -g)" \
  -v "$PWD":/app \
  -w /app \
  composer:2.9.2 \
  require --dev agents-ready/laravel-installer:^0.2
```

Run the installer:

```bash
docker run --rm -it \
  -u "$(id -u):$(id -g)" \
  -v "$PWD":/app \
  -w /app \
  --entrypoint php \
  composer:2.9.2 \
  artisan agents-ready:install \
    --host-root="$(pwd)" \
    --traefik-home="$(cd ../shared-traefik && pwd)" \
    --services-home="$(cd ../shared-services && pwd)"
```

After that, use the generated wrapper:

```bash
./local up --wait
./local artisan about
./local test
```

## Existing App

Install the package as a development dependency in the Laravel application:

```bash
composer require --dev agents-ready/laravel-installer
```

Run the installer interactively. The three host paths are required arguments; project slug, runtime, and database can be selected from prompts.

```bash
php artisan agents-ready:install \
  --host-root="$(pwd)" \
  --traefik-home="$(cd ../shared-traefik && pwd)" \
  --services-home="$(cd ../shared-services && pwd)"
```

For non-interactive installs, add `--no-interaction` and pass any desired choices as options:

```bash
php artisan agents-ready:install \
  --host-root=/absolute/path/to/app \
  --traefik-home=/absolute/path/to/shared-traefik \
  --services-home=/absolute/path/to/shared-services \
  --runtime=frankenphp \
  --database=pgsql \
  --project-slug=real-app \
  --no-interaction
```

When installing from Docker, `--host-root` should be the real host checkout path. If the Laravel app still has the skeleton Composer name `laravel/laravel`, Agents-Ready derives the default project slug from this host path instead of the container mount path such as `/app`.

Run optional conventions separately when you want project/team agent workflow files:

```bash
php artisan agents-ready:conventions
```

## Options

Runtime options:

```bash
php artisan agents-ready:install --runtime=frankenphp
php artisan agents-ready:install --runtime=nginx-php-fpm
```

Database options:

```bash
php artisan agents-ready:install --database=pgsql
php artisan agents-ready:install --database=mysql
```

Optional Horizon service:

```bash
php artisan agents-ready:install --horizon
```

Project identity and routing:

```bash
php artisan agents-ready:install --project-slug=real-app
php artisan agents-ready:install --route-domain=custom.localhost
```

`--project-slug` is the normal option for local resource identity. It is normalized, so `Real App` becomes `real-app`, and the default route domain becomes `<project-slug>.localhost`. `--route-domain` is an advanced full-domain override; it changes routing without changing the project slug.

Optional project conventions:

```bash
php artisan agents-ready:conventions --decision-notes
php artisan agents-ready:conventions --local-agent-notes
php artisan agents-ready:conventions --agent-skills
```

Core install always includes the canonical worktree lifecycle skill under `tools/agents-ready/skills/worktree-lifecycle/SKILL.md`. The `--agent-skills` convention mirrors skills into tracked `.ai/skills`, adds ignored activation-link support such as `.agents/skills`, and enables `./local agents link` through optional wrapper hooks.

Existing project candidate mode:

```bash
php artisan agents-ready:install --existing-project-candidates
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

If the agent skills convention is installed, run `./local agents link` to populate ignored activation links.

## Identity And Env

Agents-Ready identifies checkouts by `projectId + worktreeId` in `.agents-ready/worktree.json`.

For the main checkout, both `projectId` and `worktreeId` are the project slug. Worktree route hosts are derived from the main checkout route domain.

Runtime `.env` files contain app/runtime compatibility values such as `APP_URL`, `DB_*`, `REDIS_*`, `COMPOSE_PROJECT_NAME`, and `APP_SERVICE`. Agents-Ready identity is intentionally not written into `.env`.

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

Smoke workspaces default to `/tmp/opencode/agents-ready-installer-smoke` and clean recorded resources on successful runs.
