#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

mkdir -p test-results/zap
chmod a+w test-results/zap
bash scripts/testing/with-server.sh docker run --rm --network host \
  --mount "type=bind,source=$PWD/test-results/zap,target=/zap/wrk" \
  --mount "type=bind,source=$PWD/security/zap-baseline.yaml,target=/zap/expedflow-baseline.yaml,readonly" \
  ghcr.io/zaproxy/zaproxy@sha256:30b1344efc23bcb7568c56e61a12b907c6e8072b43a49f86318c3f9b9e56f2a2 \
  zap.sh -cmd -autorun /zap/expedflow-baseline.yaml
