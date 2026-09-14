# Короткие команды для повседневной работы.
# Всё запускается внутри контейнеров — на хосте ничего ставить не нужно.

.DEFAULT_GOAL := help
DC := docker compose
DCO := docker compose --profile with-ollama

help: ## Показать список команд
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

up: ## Поднять всё: сервисы, модели, прогрев и проверку. GPU=amd — с видеокартой
	@GPU=$(GPU) bash deploy/setup.sh || true

down: ## Остановить всё
	$(DCO) down

restart: ## Перезапустить
	$(DCO) restart

build: ## Пересобрать образы
	$(DCO) build

logs: ## Смотреть логи приложения
	$(DC) logs -f app worker

shell: ## Консоль внутри контейнера приложения
	$(DC) exec app bash

diagnose: ## Проверить, что база, очередь и нейросеть на месте
	$(DC) exec app php artisan aihub:diagnose

migrate: ## Применить миграции
	$(DC) exec app php artisan migrate --force

sync: ## Обновить все источники знаний прямо сейчас
	$(DC) exec app php artisan knowledge:sync

reindex: ## Переиндексировать всю базу знаний заново
	$(DC) exec app php artisan knowledge:reindex --all

test: ## Прогнать тесты
	@$(DC) exec -T postgres psql -U aihub -d aihub -c "CREATE DATABASE aihub_testing OWNER aihub" 2>/dev/null || true
	$(DC) exec app php artisan test

assets: ## Пересобрать фронтенд после правки шаблонов (нужен npm на хосте)
	npm run build

assets-docker: ## То же, но через контейнер Node — если npm на хосте нет
	docker run --rm -u $$(id -u):$$(id -g) -v "$$PWD":/app -w /app node:20-alpine \
		sh -c "npm ci --no-audit --no-fund && npm run build"

warmup: ## Прогреть модели, чтобы первый вопрос не ждал
	$(DC) exec app php artisan aihub:warmup

bench: ## Сравнить модели: скорость и умение вызывать инструменты
	$(DC) exec app php artisan aihub:bench $(ARGS)

check: ## Проверить машину перед установкой (ничего не меняет)
	bash deploy/check-machine.sh

deploy: ## Обновить проект из git и перезапустить
	./deploy.sh

https: ## Включить HTTPS (нужен для записи голоса с телефонов)
	@read -p "Адрес моноблока в сети (IP или имя): " host; \
	./deploy/make-cert.sh $$host && \
	cp docker/nginx/ssl.conf.template docker/nginx/conf.d/ssl.conf && \
	$(DC) restart nginx && \
	echo "Готово. Откройте https://$$host:$$(grep '^APP_SSL_PORT=' .env | cut -d= -f2)"

models: ## Скачать модели в Ollama (нужен интернет)
	$(DC) exec ollama ollama pull $$(grep '^LLM_MODEL=' .env | cut -d= -f2)
	$(DC) exec ollama ollama pull $$(grep '^LLM_EMBEDDING_MODEL=' .env | cut -d= -f2)

.PHONY: help up down restart build logs shell diagnose migrate sync reindex test assets assets-docker warmup bench check deploy https models
