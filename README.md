
# voitity-api

Voitity API with Laravel 12, Docker, PostgreSQL (pgVector)

## Getting Started

These steps will get your development environment up and running:

### Prerequisites

- [Docker](https://www.docker.com/products/docker-desktop) installed
- [Git](https://git-scm.com/)

### Steps

1. **Clone the repository:**
	```sh
	git clone <repo-url>
	cd voitity-api
	```

2. **Build Docker containers:**
	```sh
	docker compose build
	```

3. **Start the database (PostgreSQL with pgVector):**
	```sh
	docker compose up -d db
	```


4. **Copy and configure the environment file:**
	```sh
	cp src/.env.example src/.env
	# Edit src/.env if needed (default is set for Docker Postgres)
	```

5. **Start all services (Laravel app and database):**
	```sh
	docker compose up -d
	```

6. **Install Composer dependencies (if needed):**
	```sh
	docker compose exec app composer install
	```

7. **Run Laravel migrations:**
	```sh
	docker compose exec app php artisan migrate
	```

8. **Access the app:**
	- Open [http://localhost:8000](http://localhost:8000) in your browser.

---

**Default database credentials (see `docker-compose.yml` and `src/.env`):**

- DB_HOST=db
- DB_PORT=5432
- DB_DATABASE=voitity
- DB_USERNAME=voitity
- DB_PASSWORD=voitity

---

**Useful commands:**

- Run artisan commands:
  ```sh
  docker compose exec app php artisan <command>
  ```
- Install new Composer packages:
  ```sh
  docker compose exec app composer require <package>
  ```
