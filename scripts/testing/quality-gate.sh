#!/usr/bin/env bash
set -euo pipefail

case "${1:?GitHub event name is required}" in
  schedule|release|workflow_dispatch) extended=success ;;
  pull_request|push) extended=skipped ;;
  *) printf 'Unexpected event: %s\n' "$1" >&2; exit 1 ;;
esac

jq -e --arg extended "$extended" '
  def core: [
    "backend-quality", "backend-tests", "database-tests", "frontend-quality",
    "frontend-tests", "api-contract", "security", "codeql", "build", "e2e",
    "accessibility", "storage-integration"
  ];
  def nightly: ["mutation", "performance", "dynamic-security"];
  (keys == ((core + nightly) | sort))
  and ([. as $needs | core[] | $needs[.].result] | all(. == "success"))
  and ([. as $needs | nightly[] | $needs[.].result] | all(. == $extended))
' <<< "${NEEDS_RESULTS:?GitHub job outcomes are required}"
