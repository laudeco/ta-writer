USERID=$(shell id -u)
GROUPID=$(shell id -g)
RUN=USERID=$(USERID) GROUPID=$(GROUPID) docker compose run --rm php

.PHONY: help build start console composer-install test stan sh publish

help: ## Shows the available commands
	@cat $(MAKEFILE_LIST) | grep -e "^[a-zA-Z_\-]*: *.*## *" | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

build: ## Builds the PHP image (PHP 8.1, intl, zip, mbstring, dom, xml)
	docker compose build

composer-install: ## Installs the PHP dependencies (vendor/ is not committed)
	$(RUN) composer install --no-scripts

start: ## Serves the API and the UI on http://127.0.0.1:8080
	USERID=$(USERID) GROUPID=$(GROUPID) docker compose up

console: ## Runs a console command. Usage: make console c="about"
	$(RUN) php bin/console $(c)

test: ## Runs the PHPUnit suite
	$(RUN) php vendor/bin/phpunit

stan: ## Runs PHPStan (level 6)
	$(RUN) php vendor/bin/phpstan analyse --no-progress --memory-limit=1G

publish: ## Publishes a stored analysis. Usage: make publish d=20261123_ta-writer f=markdown
	$(RUN) php bin/console app:ta:publish $(d) --format=$(f)

sh: ## Opens a shell in the PHP container
	$(RUN) sh
