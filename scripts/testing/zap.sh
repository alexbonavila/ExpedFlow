#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

mkdir -p test-results/zap
chmod a+w test-results/zap
rm -f test-results/zap/zap.json
bash scripts/testing/with-server.sh docker run --rm --network host \
  --mount "type=bind,source=$PWD/test-results/zap,target=/zap/wrk" \
  --mount "type=bind,source=$PWD/security/zap-baseline.yaml,target=/zap/expedflow-baseline.yaml,readonly" \
  ghcr.io/zaproxy/zaproxy@sha256:30b1344efc23bcb7568c56e61a12b907c6e8072b43a49f86318c3f9b9e56f2a2 \
  zap.sh -cmd -autorun /zap/expedflow-baseline.yaml

# Check each report entry because ZAP can group alerts with different confidence by rule.
if ! jq -e '
  (.site | type == "array" and length > 0)
  and all(.site[].alerts[]; (.riskcode | tonumber) == 0 or (.confidence | tonumber) == 0)
' test-results/zap/zap.json >/dev/null; then
  printf '%s\n' 'The ZAP report is missing, invalid, or contains a Low or higher finding.' >&2
  exit 2
fi
