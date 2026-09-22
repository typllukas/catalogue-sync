HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)
export HOST_UID
export HOST_GID

DC = docker compose
EXEC = $(DC) exec -T --user $(HOST_UID):$(HOST_GID)
PHP = $(EXEC) php
CONSOLE = $(PHP) php bin/console
# APP_DEBUG=0: Doctrine's debug middleware keeps every query, a million products run out of memory
CONSOLE_BULK = $(EXEC) -e APP_DEBUG=0 php php bin/console

.DEFAULT_GOAL := help
.PHONY: help setup up down logs shell check schema-validate phpcs phpcs-fix check-mappings check-drift check-drift-all phpstan rector rector-fix test-db test migrate warm seed feed reindex reset es-indices

help: ## List the available targets
	@awk -F: '/^[a-z-]+:/ { desc = ""; if (match($$0, /## /)) desc = substr($$0, RSTART + 3); printf "  \033[36m%-16s\033[0m %s\n", $$1, desc }' $(MAKEFILE_LIST)

## --- containers ---

setup: ## Fresh clone: containers, dependencies, schema, data, the index. Drops any data already there
	@$(MAKE) --no-print-directory up
	$(PHP) composer install --no-interaction
	@$(MAKE) --no-print-directory reset

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

check: phpcs check-mappings phpstan rector schema-validate test

phpcs:
	$(PHP) vendor/bin/phpcs

phpcs-fix:
	$(PHP) vendor/bin/phpcbf

check-mappings: ## Find indexed fields no query reads
	$(CONSOLE) catalogue-sync:dev:check-mappings

phpstan:
	$(PHP) vendor/bin/phpstan analyse --no-progress --memory-limit=256M

rector: ## Rector, dry run
	$(PHP) vendor/bin/rector process --dry-run --no-progress-bar

rector-fix: ## Rector fix
	$(PHP) vendor/bin/rector process --no-progress-bar

schema-validate: test-db
	$(CONSOLE) doctrine:schema:validate --env=test

test-db: ## Create the test database, bring it up to date and load the fixtures
	$(CONSOLE) doctrine:database:create --env=test --if-not-exists
	$(CONSOLE) doctrine:migrations:migrate --env=test --no-interaction
	$(CONSOLE) doctrine:fixtures:load --env=test --no-interaction --quiet

test: test-db
	$(PHP) vendor/bin/phpunit

## --- data and indices ---

migrate: ## Run the migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction
	$(CONSOLE) doctrine:schema:validate

warm: ## Rebuild the compiled caches the bulk commands and PHPStan read
    # without debug Symfony never rechecks the source, so a rename reaches the bulk commands only after this
	@$(CONSOLE_BULK) cache:clear --quiet
    # clearing takes the debug container with it, and phpstan.neon reads its xml
	@$(CONSOLE) cache:warmup --quiet

seed: warm ## Seed the catalogue and rebuild the index, needs an empty database
	$(CONSOLE_BULK) catalogue-sync:dev:generate-data --products=$(or $(PRODUCTS),10000)
	@$(MAKE) --no-print-directory reindex

feed: warm ## Run a simulated daily supplier file over the catalogue, override like 'ROWS=1200000 make feed'
	$(CONSOLE_BULK) catalogue-sync:dev:generate-feed $(if $(ROWS),--rows=$(ROWS))

reindex: warm ## Rebuild the index and switch the alias
	$(CONSOLE_BULK) catalogue-sync:index:reindex

reset: ## Rebuild the schema and seed both stores, override like 'PRODUCTS=1000000 make reset'
	$(CONSOLE) doctrine:schema:drop --force --full-database
	@$(MAKE) --no-print-directory migrate
	@$(MAKE) --no-print-directory seed

check-drift: ## Compare the 1 000 most recently changed products against the index
	$(CONSOLE) catalogue-sync:sync:check-drift --recently-changed=1000

check-drift-all: ## Compare every product
	$(CONSOLE_BULK) catalogue-sync:sync:check-drift

es-indices: ## List the indices and aliases in Elasticsearch
	@$(DC) exec -T elasticsearch curl -s 'localhost:9200/_cat/indices/products*?h=index,docs.count,store.size&v'
	@$(DC) exec -T elasticsearch curl -s 'localhost:9200/_cat/aliases?h=alias,index' | grep -E '^products'
