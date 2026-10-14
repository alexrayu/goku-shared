COMPOSE = docker compose --env-file versions.env
# Host uid, so files the container writes stay editable on the host.
RUN     = $(COMPOSE) run --rm --user "$$(id -u):$$(id -g)" -e COMPOSER_HOME=/tmp/composer php

.PHONY: up down install stan schema test worker release

up:
	$(COMPOSE) up -d --build

down:
	$(COMPOSE) down

install:
	$(RUN) composer install

stan:
	$(RUN) sh -c "bin/console cache:warmup --env=dev && vendor/bin/phpstan analyse --memory-limit=1G"

schema:
	$(RUN) bin/console doctrine:schema:validate --skip-sync

test:
	$(RUN) vendor/bin/phpunit

release:
	$(RUN) bin/release

# What the host's cron runs every minute.
worker:
	$(RUN) bin/console messenger:consume async --time-limit=55 --memory-limit=128M
