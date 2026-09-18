HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)
export HOST_UID
export HOST_GID

DC = docker compose
EXEC = $(DC) exec -T --user $(HOST_UID):$(HOST_GID)
PHP = $(EXEC) php
CONSOLE = $(PHP) php bin/console

.DEFAULT_GOAL := help
.PHONY: help up down logs shell

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
