.PHONY: up down build fresh logs shell mysql

up:
	docker compose up -d --build
	@echo "AMANAH: http://localhost:8080"
	@echo "Login Super Admin: admin@alfatihah.com / Admin#2026"

down:
	docker compose down

build:
	docker compose build

fresh:
	docker compose down -v
	docker compose up -d --build
	@echo "AMANAH: http://localhost:8080"
	@echo "Login Super Admin: admin@alfatihah.com / Admin#2026"

logs:
	docker compose logs -f

shell:
	docker compose exec app bash

mysql:
	docker compose exec db mariadb -uroot -proot gudang_bayi
