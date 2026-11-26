#!/usr/bin/env bash
set -euo pipefail
container=${1:?A MySQL service container ID is required}
[[ "$container" =~ ^[a-f0-9]+$ ]]
: "${GITHUB_ENV:?Run this script on a GitHub Actions runner}"

password=$(openssl rand -hex 24)
key="base64:$(openssl rand -base64 32)"
printf '::add-mask::%s\n' "$password" "$key"
{
  printf "CREATE USER 'expedtest'@'%%' IDENTIFIED BY '%s';\n" "$password"
  printf '%s\n' "GRANT ALL PRIVILEGES ON \`expedflow\\_test\`.* TO 'expedtest'@'%';"
} | docker exec -i "$container" mysql --protocol=socket --user=root

{
  printf 'APP_ENV=testing\nAPP_DEBUG=false\nAPP_KEY=%s\n' "$key"
  printf 'DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=expedflow_test\n'
  printf 'DB_USERNAME=expedtest\nDB_PASSWORD=%s\nDB_URL=\nDB_SOCKET=\n' "$password"
} >> "$GITHUB_ENV"

if [[ ! -e .env.testing ]]; then
  (umask 077; cp .env.testing.example .env.testing)
fi
