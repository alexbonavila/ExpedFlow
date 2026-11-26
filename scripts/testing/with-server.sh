#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

log_dir=test-results/services
mkdir -p "$log_dir"
if curl --noproxy '*' --silent --max-time 1 --output /dev/null http://127.0.0.1:8001/up; then
  printf '%s\n' 'Port 8001 is already serving HTTP.' >&2
  exit 1
fi

bash scripts/testing/serve.sh >"$log_dir/laravel.log" 2>&1 &
server=$!
trap 'kill "$server" 2>/dev/null || true; wait "$server" 2>/dev/null || true' EXIT
for ((attempt=0; attempt<30; attempt++)); do
  if ! kill -0 "$server" 2>/dev/null; then
    break
  fi
  if curl --noproxy '*' --silent --fail --max-time 1 --output /dev/null http://127.0.0.1:8001/up; then
    "$@"
    exit
  fi
  sleep 1
done

cat "$log_dir/laravel.log" >&2
exit 1
