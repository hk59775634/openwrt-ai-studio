ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
COMPOSE := docker compose -f $(ROOT)/infrastructure/compose/docker-compose.yml --project-directory $(ROOT)/infrastructure/compose

.PHONY: bootstrap up down logs ps images prepare-openwrt test

bootstrap:
	$(ROOT)/scripts/bootstrap.sh

up: bootstrap
	$(COMPOSE) up -d --build

down:
	$(COMPOSE) down

logs:
	$(COMPOSE) logs -f --tail=200

ps:
	$(COMPOSE) ps

images:
	$(COMPOSE) --profile sandbox build

prepare-openwrt:
	$(COMPOSE) exec api php artisan studio:prepare-openwrt

test:
	$(COMPOSE) exec api php artisan test
