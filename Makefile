# Convenience wrapper over docker compose and artisan.
SHELL := /bin/bash
DC    := docker compose
PHP   := $(DC) exec -T php

.DEFAULT_GOAL := help
.PHONY: help up down build fresh seed test lint fix logs shell tinker \
        queue-restart audit import-bandar-abbas apps-analyze apps-test apk

help: ## Show this help
	@grep -hE '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-22s\033[0m %s\n", $$1, $$2}'

up: ## Start the stack
	$(DC) up -d
	@echo "  app       http://localhost:8000"
	@echo "  websocket ws://localhost:8080"

down: ## Stop the stack
	$(DC) down

build: ## Rebuild images
	$(DC) build --no-cache

fresh: ## Drop, migrate and seed the database
	$(PHP) php artisan migrate:fresh --seed

seed: ## Seed without dropping
	$(PHP) php artisan db:seed

test: ## Run the PHP test suite
	$(PHP) php artisan test

lint: ## Check code style
	$(PHP) ./vendor/bin/pint --test

fix: ## Apply code style fixes
	$(PHP) ./vendor/bin/pint

audit: ## Verify double-entry ledger integrity
	$(PHP) php artisan transit:ledger:audit

import-bandar-abbas: ## Reload the Bandar Abbas sample network
	$(PHP) php artisan db:seed --class=BandarAbbasNetworkSeeder

queue-restart: ## Restart queue workers after a deploy
	$(PHP) php artisan queue:restart

logs: ## Tail all container logs
	$(DC) logs -f --tail=100

shell: ## Shell into the app container
	$(DC) exec php sh

tinker: ## Open a REPL
	$(PHP) php artisan tinker

# ── Flutter apps ────────────────────────────────────────────────────────────

apps-analyze: ## Static-analyse the shared package and all three apps
	cd packages/hamsafar_core && flutter analyze
	@for app in passenger driver merchant; do \
	  echo "── $$app"; (cd apps/$$app && flutter analyze); \
	done

apps-test: ## Run the Flutter test suites
	cd packages/hamsafar_core && flutter test
	@for app in passenger driver merchant; do \
	  echo "── $$app"; (cd apps/$$app && flutter test); \
	done

apk: ## Build release APKs (set API_URL=https://…)
	@test -n "$(API_URL)" || (echo "API_URL is required"; exit 1)
	@for app in passenger driver merchant; do \
	  echo "── building $$app"; \
	  (cd apps/$$app && flutter build apk --release --dart-define=API_BASE_URL=$(API_URL)); \
	done
