# Executables (local)
DOCKER_COMP = docker compose

# Docker containers
PHP_CONT = $(DOCKER_COMP) exec -w /srv/app php

# Executables
PHP      = $(PHP_CONT) php
COMPOSER = $(PHP_CONT) composer

.DEFAULT_GOAL = help

.PHONY: help build up down logs php install ci static test
help:
	@awk 'BEGIN {FS = ":.*##"; printf "Usage: make \033[36m<target>\033[0m\n"} /^[a-z\/\.A-Z0-9_-]+:.*?##/ { printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } ' $(MAKEFILE_LIST)

## —— Docker —————————————————————————————————————————————————————————————————
build: ## Build the Docker images
	@$(DOCKER_COMP) build --pull --no-cache

up: ## Start the containers in detached mode
	@$(DOCKER_COMP) up --detach

down: ## Stop the containers
	@$(DOCKER_COMP) down --remove-orphans

logs: ## Show live logs
	@$(DOCKER_COMP) logs --tail=0 --follow

php: ## Open a shell in the PHP container
	$(PHP_CONT) sh

## —— App ————————————————————————————————————————————————————————————————————
install: ## Install dependencies and create the SQLite schema
	$(COMPOSER) install
	$(PHP) bin/console doctrine:schema:update --force

## —— CI —————————————————————————————————————————————————————————————————————
ci: static test ## Run static analysis and tests

static: ## Run static analysis tools
	$(COMPOSER) static:run

test: ## Run tests
	$(COMPOSER) test:run
