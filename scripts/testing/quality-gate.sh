#!/usr/bin/env bash
set -euo pipefail

case "${1:?GitHub event name is required}" in
  schedule|release) extended=success; mutation=full ;;
  workflow_dispatch) extended=success; mutation="${DISPATCH_MUTATION_MODE:-full}" ;;
  pull_request) extended=skipped; mutation=incremental ;;
  push) extended=skipped; mutation=skipped ;;
  *) printf 'Unexpected event: %s\n' "$1" >&2; exit 1 ;;
esac

case "$mutation" in full|incremental|skipped) ;; *) exit 1 ;; esac
if [[ "$1" == workflow_dispatch && "$mutation" == skipped ]]; then exit 1; fi

jq -e --arg extended "$extended" --arg mutation "$mutation" '
  def core: [
    "backend-quality", "backend-tests", "database-tests", "frontend-quality",
    "frontend-tests", "api-contract", "security", "codeql", "build", "e2e",
    "accessibility", "storage-integration"
  ];
  def mutation_jobs: ["mutation-plan", "mutation-shards", "mutation"];
  def nightly: ["performance", "dynamic-security"];
  (keys == ((core + mutation_jobs + nightly) | sort))
  and ([. as $needs | core[] | $needs[.].result] | all(. == "success"))
  and ([. as $needs | nightly[] | $needs[.].result] | all(. == $extended))
  and (
    if $mutation != "skipped" then
      .["mutation-plan"].result == "success" and .mutation.result == "success"
      and .["mutation-plan"].outputs.mode == $mutation
      and (
        (.["mutation-plan"].outputs.work == "yes" and .["mutation-shards"].result == "success")
        or ($mutation == "incremental" and .["mutation-plan"].outputs.work == "no" and .["mutation-shards"].result == "skipped")
      )
    else
      ([. as $needs | mutation_jobs[] | $needs[.].result] | all(. == "skipped"))
    end
  )
' <<< "${NEEDS_RESULTS:?GitHub job outcomes are required}"
