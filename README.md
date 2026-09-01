# voitity-api

Voitity API built with Laravel 12, Docker, PostgreSQL, and pgVector.

This README covers the local Docker workflow for running the API, database, migrations, Swagger docs, and tests.

The complete local setup for the API, public web, admin, and chat worker is in
[`LOCAL_SETUP.md`](LOCAL_SETUP.md).

Application behavior, plan limits, quota reservations, public messaging
capabilities, and production process requirements are documented in
[`docs/subscriptions-and-usage.md`](docs/subscriptions-and-usage.md).
Payment methods, checkout, renewals, credit charges, webhooks, and payment
security are documented in [`docs/payments.md`](docs/payments.md).
The internal activation funnel, campaign attribution, report endpoints, and
default profile features are documented in
[`docs/ACTIVATION_REPORTS.md`](docs/ACTIVATION_REPORTS.md).
The unauthenticated published-profile surface, encrypted visitor chat sessions,
rate limits, and deployment order are documented in
[`docs/public-profile-api.md`](docs/public-profile-api.md).
Use
[`voitity-subscription-limit-testing`](.codex/skills/voitity-subscription-limit-testing/SKILL.md)
when adding plans or changing prices and limits. The latest local validation is
recorded in
[`docs/subscription-limit-test-run-2026-07-29.md`](docs/subscription-limit-test-run-2026-07-29.md).

## Requirements

- Docker Desktop or a compatible Docker Engine
- Docker Compose v2
- Git

## Services

`docker-compose.yml` defines:

- `app`: Laravel API container, exposed on `http://localhost:8000`
- `queue`: persistent Laravel database queue worker
- `scheduler`: persistent Laravel scheduler that runs due commands
- `db`: PostgreSQL with pgVector, exposed on `localhost:5433`
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
- `voitity-laravel-queue`
- `voitity-laravel-scheduler`
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

Inspect background process logs:

```sh
docker compose logs -f queue scheduler
```

Production must run exactly one scheduler process and at least one queue worker. The scheduler executes subscription expiration every minute, scans due recurring billing and durable payment retries every five minutes, releases stale usage reservations every ten minutes, and resets due usage periods daily. The queue worker processes AI, voice, contact, notification, and recurring-billing jobs.

Use the same immutable application image for these three production process types:

```sh
# Web
php artisan serve --host=0.0.0.0 --port=8000 --no-reload

# Worker
php artisan queue:work --sleep=3 --tries=3 --timeout=300 --max-time=3600

# Scheduler, exactly one replica
php artisan schedule:work
```

All three processes must receive the same application release and environment, including `APP_KEY`, database credentials, `QUEUE_CONNECTION=database`, and `CACHE_STORE=database`. Run `php artisan migrate --force` once per release before serving traffic. Recreate the worker and scheduler containers on each release so long-lived processes load the new code; the worker also exits after one hour as a fallback and relies on the process manager restart policy.

Do not reuse the local Compose database credentials or source bind mounts in production. Production orchestration must keep the worker stop grace period above the 300-second job timeout and must not enable `RUN_QUEUE_WORKER` on the web process when a dedicated worker is running.

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
