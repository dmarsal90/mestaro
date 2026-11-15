# Everything runs in containers. Usage: make up | down | sh s=platform | art s=platform c="migrate" | test s=platform | e2e
s ?= platform
DC = docker compose

up:    ; $(DC) up -d
down:  ; $(DC) down
build: ; $(DC) build
sh:    ; $(DC) exec $(s) sh
art:   ; $(DC) exec $(s) php artisan $(c)
comp:  ; $(DC) exec $(s) composer $(c)
test:  ; $(DC) exec $(s) vendor/bin/pest $(a)
e2e:   ; bash e2e/run.sh
lint:  ; $(DC) exec $(s) sh -c 'vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G'
