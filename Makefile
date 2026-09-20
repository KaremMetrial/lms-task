# Convenience wrappers. Every target runs inside the container, so results do not
# depend on what happens to be installed on the host.

DC := docker compose
APP := $(DC) exec -T app

.DEFAULT_GOAL := help
.PHONY: help env up down build restart logs shell mysql redis migrate fresh seed test test-filter stan lint lint-fix audit worker-kill horizon ps

help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

env: ## Write your host UID/GID into .env (needed once, before build)
	@grep -q '^UID=' .env && sed -i "s/^UID=.*/UID=$$(id -u)/" .env || echo "UID=$$(id -u)" >> .env
	@grep -q '^GID=' .env && sed -i "s/^GID=.*/GID=$$(id -g)/" .env || echo "GID=$$(id -g)" >> .env
	@echo "UID/GID written to .env: $$(grep -E '^(UID|GID)=' .env | tr '\n' ' ')"

build: env ## Build the app image, matching the container user to your host user
	# UID/GID come from .env, which docker compose reads for ${VAR} substitution.
	#
	# Deliberately NOT `UID=$$(id -u) docker compose build` — UID is a readonly
	# variable in bash, so that form fails outright on most Linux shells. Writing it
	# to .env works in every shell and survives across commands.
	$(DC) build

up: ## Start the stack
	$(DC) up -d

down: ## Stop the stack (volumes kept)
	$(DC) down

restart: ## Recreate the PHP services
	$(DC) up -d --force-recreate app worker scheduler

ps: ## Show service status
	$(DC) ps

logs: ## Tail logs for all services
	$(DC) logs -f --tail=100

shell: ## Interactive shell in the app container
	$(DC) exec app bash

mysql: ## MySQL client on the dev database
	$(DC) exec mysql mysql -ulms -psecret lms

redis: ## Redis CLI
	$(DC) exec redis redis-cli

migrate: ## Run migrations
	$(APP) php artisan migrate

fresh: ## Drop everything and re-migrate with seed data
	$(APP) php artisan migrate:fresh --seed

seed: ## Run seeders
	$(APP) php artisan db:seed

test: ## Run the full test suite (against MySQL lms_test)
	$(APP) php artisan test

test-filter: ## Run a subset: make test-filter F=Payout
	$(APP) php artisan test --filter=$(F)

stan: ## Static analysis
	$(APP) ./vendor/bin/phpstan analyse --memory-limit=1G

lint: ## Check code style
	$(APP) ./vendor/bin/pint --test

lint-fix: ## Fix code style
	$(APP) ./vendor/bin/pint

audit: ## Report dependency advisories (3 are knowingly ignored — see composer.json)
	$(APP) composer audit

worker-kill: ## Hard-kill the queue worker mid-job, to prove retries never double-pay
	$(DC) kill -s SIGKILL worker

horizon: ## Start the optional Horizon dashboard at /horizon
	$(DC) --profile horizon up -d horizon
