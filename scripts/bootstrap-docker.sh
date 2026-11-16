#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ ! -f .env ]]; then echo 'Copy .env.example to .env and set strong values first'; exit 1; fi
if [[ ! -d backend ]]; then
  docker run --rm -u "$(id -u):$(id -g)" -v "$PWD:/work" -w /work composer:2 create-project 'laravel/laravel:^13.0' backend
fi
cp -a backend-overlay/. backend/
if [[ ! -f backend/.env ]]; then cp deploy/backend.env.example backend/.env; fi
printf 'Edit backend/.env: DB_DATABASE, DB_USERNAME, DB_PASSWORD, APP_URL, SYNC_API_TOKEN, LINE secrets\n'
printf 'Then run: docker compose build && docker compose up -d && set APP_KEY in backend/.env on host before starting Docker; then docker compose exec app php artisan migrate --force\n'
