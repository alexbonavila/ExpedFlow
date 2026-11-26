#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

mkdir -p test-results/performance
bash scripts/testing/with-server.sh docker run --rm --network host \
  --user "$(id -u):$(id -g)" \
  --mount "type=bind,source=$PWD/performance,target=/suite,readonly" \
  --mount "type=bind,source=$PWD/test-results/performance,target=/reports" \
  grafana/k6@sha256:2072ea9eafa596532d9aee0cc0e0a50cfb0e7fb703981a46179af5f4f22c5ea4 \
  run --summary-export=/reports/k6-summary.json "$@" /suite/smoke.js
