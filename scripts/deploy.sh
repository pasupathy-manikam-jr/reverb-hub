#!/usr/bin/env bash
#
# Deploy (run ON the server):  cd ~/reverb-hub && bash scripts/deploy.sh
# Pulls main, installs PHP deps, re-caches config and restarts Reverb
# (systemd brings it back up). No database, no frontend build.
#
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

git fetch -q origin main
git reset -q --hard origin/main

composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php artisan config:cache
php artisan reverb:restart

echo "==> Deployed $(git rev-parse --short HEAD)"
