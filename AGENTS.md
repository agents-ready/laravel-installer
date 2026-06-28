# Agent Instructions

This repository implements the Composer dev package for Agents-Ready Laravel.

## Core Rules

- Keep this package installable as a Laravel dev dependency.
- Prefer Artisan commands over a global binary until there is a concrete need for one.
- Generated app files must be conflict-safe: preflight first, refuse existing differing files, and keep installs idempotent.
- Do not require Laravel Sail. A `./local sail ...` compatibility command may forward to the package's Compose-backed wrapper.
- Keep V1 focused on FrankenPHP, PostgreSQL, Valkey, Traefik, worktree lifecycle, and agent guidance.
- Use `laravel/boost` as a reference for agent-facing install/update flows, but do not copy its active MCP or browser-logging behavior by default.
- Track canonical skills in `.ai/skills`; use ignored activation folders such as `.agents/skills` and optional tool-specific symlinks.

## Verification

- Use Docker-first commands: `make install`, `make test`, `make analyse`, and `make format-test`.
- Use `PHP_VERSION=8.4.15 make build test` or `PHP_VERSION=8.3.28 make build test` when checking another local PHP version.
- Run `make test` after command or service-provider changes.
- Run `make analyse` after type-sensitive changes.
- Run `make format-test` before finalizing formatting-heavy changes.
