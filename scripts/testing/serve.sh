#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

if [[ ! -s public/build/manifest.json || -e public/hot ]]; then
  printf '%s\n' 'Build Vite assets and stop the Vite development server before running the harness.' >&2
  exit 1
fi

export APP_ENV=testing APP_DEBUG=false SESSION_SECURE_COOKIE=false APP_URL=http://127.0.0.1:8001
export APP_KEY="${APP_KEY:-base64:$(openssl rand -base64 32)}"
php tests/Support/check-database.php
php artisan migrate --force --no-interaction
exec php -d expose_php=Off -S 127.0.0.1:8001 -t public scripts/testing/router.php
