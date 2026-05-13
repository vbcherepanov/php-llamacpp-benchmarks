DC := docker compose
PHP := $(DC) run --rm php

.PHONY: help build install fixtures db bench case-study all clean shell down ps logs stan

help:
	@echo "Targets:"
	@echo "  make build       - build the php image"
	@echo "  make install     - composer install (in container)"
	@echo "  make fixtures    - generate fixture data (lookup.bin/json, records.csv, countries.*)"
	@echo "  make db          - start postgres + create case-study table"
	@echo "  make bench       - run B01..B06"
	@echo "  make case-study  - run naive vs optimized importer"
	@echo "  make all         - install, fixtures, db, bench, case-study"
	@echo "  make stan        - phpstan level 8"
	@echo "  make shell       - bash shell in php container"
	@echo "  make down        - stop all containers, drop volumes"
	@echo "  make clean       - remove fixtures and stop containers"

build:
	$(DC) build

install: build
	$(PHP) composer install --no-progress

fixtures:
	$(PHP) php bin/generate-fixtures.php

db:
	$(DC) up -d postgres
	$(PHP) php bin/setup-db.php

bench:
	$(PHP) php bin/run-all.php

case-study:
	$(PHP) php bin/run-case-study.php

scaling:
	$(PHP) php bin/run-scaling.php

scaling-smoke:
	$(PHP) php bin/run-scaling.php --smoke

all: install fixtures db bench case-study

stan:
	$(PHP) vendor/bin/phpstan analyse --memory-limit=-1

shell:
	$(PHP) bash

ps:
	$(DC) ps

logs:
	$(DC) logs -f --tail=200

down:
	$(DC) down -v

clean: down
	rm -f data/*.bin data/*.json data/*.csv
