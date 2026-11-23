USERID=$(shell id -u)
GROUPID=$(shell id -g)
RUN=USERID=$(USERID) GROUPID=$(GROUPID) docker compose run --rm php

.PHONY: help build up start down console composer-install test stan sh publish login-browser

help: ## Shows the available commands
	@cat $(MAKEFILE_LIST) | grep -e "^[a-zA-Z_\-]*: *.*## *" | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

build: ## Builds the PHP 8.4 image (intl, zip, mbstring, dom, xml, gd, mermaid-cli, Chromium)
	docker compose build

composer-install: ## Installs the PHP dependencies (vendor/ is not committed)
	$(RUN) composer install --no-scripts

up: ## Starts the application in the background (API and UI on http://127.0.0.1:8080)
	USERID=$(USERID) GROUPID=$(GROUPID) docker compose up -d

down: ## Stops the stack
	docker compose down

start: ## Starts the application in the foreground (logs in the terminal)
	USERID=$(USERID) GROUPID=$(GROUPID) docker compose up

console: ## Runs a console command. Usage: make console c="about"
	$(RUN) php bin/console $(c)

test: ## Runs the PHPUnit suite
	$(RUN) php vendor/bin/phpunit

stan: ## Runs PHPStan (level 6)
	$(RUN) php vendor/bin/phpstan analyse --no-progress --memory-limit=1G

publish: ## Publishes a stored analysis. Usage: make publish d=20261123_ta-writer f=markdown
	$(RUN) php bin/console app:ta:publish $(d) --format=$(f)

login-browser: ## Shows the browser used for the AGIR login, through noVNC (http://127.0.0.1:6080/vnc.html)
	docker compose exec -d php novnc.sh
	@echo "Open http://127.0.0.1:6080/vnc.html, start 'make publish' for agir from the UI, and log in when asked."

sh: ## Opens a shell in the PHP container
	$(RUN) sh
