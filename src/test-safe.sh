#!/bin/sh
# Safe test runner for Laravel: prevents tests from touching your Postgres DB

# Move .env out of the way if it exists
if [ -f .env ]; then
  mv .env .env.bak
fi

# Copy .env.testing to .env (if not already)
if [ -f .env.testing ]; then
  cp .env.testing .env
fi

# Run tests
php artisan test

# Restore original .env
if [ -f .env.bak ]; then
  mv .env.bak .env
fi
