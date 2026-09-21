# Convenience wrappers. Every target runs inside the container, so results do not
# depend on what happens to be installed on the host.

DC := docker compose
APP := $(DC) exec -T app

.DEFAULT_GOAL := help
.PHONY: help setup env up down build restart worker logs shell mysql redis migrate fresh seed test test-filter stan lint lint-fix audit worker-kill horizon ps

help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

setup: ## First run on a fresh clone: env, build, install, migrate, seed — in the right order
	@test -f .env || cp .env.example .env
	@$(MAKE) --no-print-directory env
	$(DC) build
	# Only the services that do not execute application code start first. The worker
	# and scheduler run `php artisan` as their main process; started before vendor/
	# exists they crash-loop on a missing autoload.php. That is exactly what the
	# previous README produced on a fresh clone.
	$(DC) up -d mysql redis app
	$(DC) exec -T app composer install --no-interaction --prefer-dist
	# Generate a key only if there is none, so re-running setup never invalidates
	# existing sessions and encrypted values.
	@grep -qE '^APP_KEY=.+' .env || $(DC) exec -T app php artisan key:generate --ansi
	$(DC) up -d
	$(DC) exec -T app php artisan migrate:fresh --seed --force
	@echo
	@echo "  Ready: http://localhost:$${APP_PORT:-8000}/admin   admin@career180.test / password"

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

horizon: ## Swap the plain worker for Horizon, with its dashboard at /horizon
	# Horizon REPLACES the worker rather than running alongside it: both consume the
	# payouts queue, and two consumers make the dashboard show only half the jobs.
	$(DC) stop worker
	$(DC) --profile horizon up -d horizon
	@echo "  Horizon is processing payouts. Dashboard: http://localhost:$${APP_PORT:-8000}/horizon"
	@echo "  Back to the plain worker: make worker"

worker: ## Swap Horizon back out for the plain queue worker
	-$(DC) --profile horizon stop horizon
	$(DC) up -d worker
