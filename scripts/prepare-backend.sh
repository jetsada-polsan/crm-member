#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
command -v composer >/dev/null || { echo 'Please install Composer first'; exit 1; }
if [ ! -d backend ]; then
  composer create-project 'laravel/laravel:^13.0' backend
fi
cp -a backend-overlay/. backend/
cd backend
composer dump-autoload
printf '
Overlay applied. Configure backend/.env then php artisan key:generate && php artisan migrate.
'
