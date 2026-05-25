# voitity-api

Voitity API built with Laravel 12, Docker, PostgreSQL, and pgVector.

This README covers the local Docker workflow for running the API, database, migrations, Swagger docs, and tests.

## Requirements

- Docker Desktop or a compatible Docker Engine
- Docker Compose v2
- Git

## Services

`docker-compose.yml` defines:

- `app`: Laravel API container, exposed on `http://localhost:8000`
- `db`: PostgreSQL with pgVector, exposed on `localhost:5432`
- `pgdata`: named volume for PostgreSQL data
- `vendor`: named volume mounted at `/var/www/html/vendor` so Composer dependencies are not hidden by the `./src` bind mount

The app container runs `docker/entrypoint.sh` before starting Laravel. The entrypoint installs Composer dependencies if `vendor/autoload.php` is missing, creates `src/.env` from `src/.env.example` if needed, and generates `APP_KEY` when it is empty.

## Start The App

From the repository root:

```sh
docker compose up -d --build
```

Check container status:

```sh
docker compose ps
```

Expected services:

- `voitity-laravel-app`
- `voitity-pgvector-db`

## Environment

The Docker database settings are defined in `docker-compose.yml` and are passed into the app container:

```env
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=voitity
DB_USERNAME=voitity
DB_PASSWORD=voitity
```

`src/.env.example` still defaults to SQLite because it is the Laravel starter default. When running through Docker, the Compose environment above is the source of truth for the app process.

Add local API keys or provider configuration in `src/.env` when needed:

```env
OPENAI_API_KEY=
VOICE_DRIVERS_ELEVENLABS_API_KEY=
VOICE_DRIVERS_ELEVENLABS_BASE_URL=https://api.elevenlabs.io
```

## Database

Run migrations:

```sh
docker compose exec app php artisan migrate
```

Check migration status:

```sh
docker compose exec app php artisan migrate:status
```

Open a PostgreSQL shell:

```sh
docker compose exec db psql -U voitity -d voitity
```

## Health Checks

API health endpoint:

```sh
curl http://localhost:8000/api/health
```

Expected response:

```json
{"message":"ok"}
```

Laravel health endpoint:

```sh
curl http://localhost:8000/up
```

## Swagger Documentation

Generate or refresh Swagger docs:

```sh
docker compose exec app php artisan l5-swagger:generate
```

Open the docs:

- `http://localhost:8000/api/documentation`

## Tests

Run all tests:

```sh
docker compose exec app php artisan test
```

Run only unit tests:

```sh
docker compose exec app php artisan test --testsuite=Unit
```

Run only feature tests:

```sh
docker compose exec app php artisan test --testsuite=Feature
```

For CI or scripts, disable TTY:

```sh
docker compose exec -T app php artisan test
```

The test environment uses `src/.env.testing`, which is configured for SQLite in-memory.

## Useful Commands

Run any Artisan command:

```sh
docker compose exec app php artisan <command>
```

Install Composer packages:

```sh
docker compose exec app composer require <package>
```

Open a shell in the app container:

```sh
docker compose exec app sh
```

View app logs:

```sh
docker compose logs -f app
```

Restart the app container:

```sh
docker compose restart app
```

Rebuild the app image:

```sh
docker compose up -d --build app
```

Stop all services:

```sh
docker compose down
```

Reset containers and named volumes, including database data and Composer dependencies:

```sh
docker compose down -v
```

## Troubleshooting

If the app container restarts with `vendor/autoload.php` missing, rebuild and restart the app:

```sh
docker compose up -d --build app
```

If Laravel cannot connect to the database, confirm both containers are running:

```sh
docker compose ps
docker compose logs db
docker compose logs app
```

If you changed environment variables, restart the app:

```sh
docker compose restart app
```
