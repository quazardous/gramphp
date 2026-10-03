DC  = docker compose
RUN = $(DC) run --rm php

.PHONY: help build install test test-memory stan cs cs-fix lint audit check shell down

help: ## List the targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-12s %s\n", $$1, $$2}'

build: ## Build the PHP image
	$(DC) build php

install: ## Install the Composer dependencies
	$(RUN) composer install

test: ## Every test: unit, memory driver, MariaDB driver (concurrency included)
	$(RUN) vendor/bin/phpunit

test-memory: ## Unit and memory driver tests only, no database
	$(RUN) vendor/bin/phpunit --exclude-group mariadb

stan: ## Static analysis (PHPStan, max level)
	$(RUN) vendor/bin/phpstan analyse --memory-limit=1G

cs: ## Check the coding style
	$(RUN) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Fix the coding style
	$(RUN) vendor/bin/php-cs-fixer fix

lint: stan cs ## Every static check: PHPStan and the coding style
	$(RUN) composer validate --strict

audit: ## Known vulnerabilities in the dependencies
	$(RUN) composer audit

check: lint test ## What CI runs

shell: ## A shell in the PHP container
	$(RUN) bash

down: ## Stop and remove the containers
	$(DC) down -v
