HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)
export HOST_UID
export HOST_GID

DC = docker compose
EXEC = $(DC) exec -T --user $(HOST_UID):$(HOST_GID)
PHP = $(EXEC) php
CONSOLE = $(PHP) php bin/console

.DEFAULT_GOAL := help
.PHONY: help up down logs shell check schema-validate phpcs phpcs-fix phpstan rector rector-fix test-db test

help: ## List the available targets
	@awk -F: '/^[a-z-]+:/ { desc = ""; if (match($$0, /## /)) desc = substr($$0, RSTART + 3); printf "  \033[36m%-16s\033[0m %s\n", $$1, desc }' $(MAKEFILE_LIST)

## --- containers ---

up:
	$(DC) up -d --wait
	@$(DC) ps --format '{{.Service}}\t{{.Status}}'

down:
	$(DC) down

logs: ## php and elasticsearch logs
	$(DC) logs -f php elasticsearch

shell: ## Shell in the php container
	$(DC) exec --user $(HOST_UID):$(HOST_GID) php bash

## --- quality ---

check: phpcs phpstan rector schema-validate test

phpcs:
	$(PHP) vendor/bin/phpcs

phpcs-fix:
	$(PHP) vendor/bin/phpcbf

phpstan:
	$(PHP) vendor/bin/phpstan analyse --no-progress --memory-limit=256M

rector: ## Rector, dry run
	$(PHP) vendor/bin/rector process --dry-run --no-progress-bar

rector-fix: ## Rector fix
	$(PHP) vendor/bin/rector process --no-progress-bar

schema-validate: test-db
	$(CONSOLE) doctrine:schema:validate --env=test

test-db: ## Create the test database and bring it up to date
	$(CONSOLE) doctrine:database:create --env=test --if-not-exists
	$(CONSOLE) doctrine:migrations:migrate --env=test --no-interaction

test: test-db
	$(PHP) vendor/bin/phpunit
