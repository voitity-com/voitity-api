#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_ROOT="$(cd "${SCRIPT_DIR}/../../.." && pwd)"

cd "${API_ROOT}"
docker compose ps
docker compose exec -T app php artisan migrate:status
docker compose exec -T app php artisan queue:monitor default --max=100

curl --fail --silent --show-error --output /dev/null http://localhost:8000/up
curl --fail --silent --show-error --output /dev/null http://localhost:3000/
curl --fail --silent --show-error --output /dev/null http://localhost:3001/

echo "Local API, admin, web, migrations, and queue are reachable."
