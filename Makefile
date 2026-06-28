PHP_VERSION ?= 8.5.1
COMPOSER_VERSION ?= 2.9.2
SMOKE_PROFILE ?= full
SMOKE_RUNTIME ?= frankenphp
SMOKE_DATABASE ?= pgsql
SMOKE_HORIZON ?= 0
SMOKE_LARAVEL_VERSION ?= ^13.0
SMOKE_PHP_VERSION ?= 8.4
SMOKE_COMPOSER_VERSION ?= 2.9.2
HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)
COMPOSE := HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) PHP_VERSION=$(PHP_VERSION) COMPOSER_VERSION=$(COMPOSER_VERSION) docker compose

.PHONY: build install update test analyse format format-test shell clean php83-test php84-test php85-test smoke smoke-install smoke-runtime smoke-http smoke-worktree smoke-full smoke-clean smoke-runner-build

build:
	$(COMPOSE) build tools

install:
	$(COMPOSE) run --rm tools install

update:
	$(COMPOSE) run --rm tools update

test:
	$(COMPOSE) run --rm tools test

analyse:
	$(COMPOSE) run --rm tools analyse

format:
	$(COMPOSE) run --rm tools format

format-test:
	$(COMPOSE) run --rm tools format:test

shell:
	$(COMPOSE) run --rm --entrypoint sh tools

clean:
	$(COMPOSE) down --volumes --remove-orphans

php83-test: PHP_VERSION=8.3.28
php83-test: install test analyse format-test

php84-test: PHP_VERSION=8.4.15
php84-test: install test analyse format-test

php85-test: PHP_VERSION=8.5.1
php85-test: install test analyse format-test

smoke-runner-build:
	docker build \
		--build-arg 'PHP_VERSION=$(SMOKE_PHP_VERSION)' \
		--build-arg 'COMPOSER_VERSION=$(SMOKE_COMPOSER_VERSION)' \
		-t 'agent-ready-installer-smoke-runner:$(SMOKE_PHP_VERSION)-$(SMOKE_COMPOSER_VERSION)' \
		docker/smoke-runner

smoke:
	AGENT_READY_SMOKE_PROFILE='$(SMOKE_PROFILE)' \
	AGENT_READY_SMOKE_RUNTIME='$(SMOKE_RUNTIME)' \
	AGENT_READY_SMOKE_DATABASE='$(SMOKE_DATABASE)' \
	AGENT_READY_SMOKE_HORIZON='$(SMOKE_HORIZON)' \
	AGENT_READY_SMOKE_LARAVEL_VERSION='$(SMOKE_LARAVEL_VERSION)' \
	AGENT_READY_SMOKE_PHP_VERSION='$(SMOKE_PHP_VERSION)' \
	AGENT_READY_SMOKE_COMPOSER_VERSION='$(SMOKE_COMPOSER_VERSION)' \
	scripts/smoke/run

smoke-install:
	$(MAKE) smoke SMOKE_PROFILE=install

smoke-runtime:
	$(MAKE) smoke SMOKE_PROFILE=runtime

smoke-http:
	$(MAKE) smoke SMOKE_PROFILE=http

smoke-worktree:
	$(MAKE) smoke SMOKE_PROFILE=worktree

smoke-full:
	$(MAKE) smoke SMOKE_PROFILE=full

smoke-clean:
	scripts/smoke/clean
