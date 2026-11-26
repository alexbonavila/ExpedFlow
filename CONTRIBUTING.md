# Contributing to ExpedFlow

## Git workflow

Work on a focused branch such as `feat/...`, `fix/...`, `test/...`, `docs/...` or `chore/...`. Use descriptive commits and a pull request for each coherent change. Keep shared branches free of force pushes. Verify the effective required checks before integration. The owner approves phase acceptance and integration.

## Local changes

Read `AGENTS.md`, the product requirements and the current phase. Keep development and testing environments isolated. Install from the checked-in lockfiles and use synthetic test data. Keep `.env`, `.env.testing`, credentials and generated reports out of Git. Follow [TESTING.md](docs/TESTING.md) for the current setup and executable checks.

## Pull requests and review

Describe the phase, scope, API and data impact, commands and actual outcomes, documentation, security implications and any known limitations. Verify accessibility and ES/CA/EN for user-facing changes. Explain migration and rollback behavior where applicable. Add a reproducible regression test for a correction when possible.

Review scope, simplicity, authorization, validation, transactions, contracts, error handling and exposure of sensitive data. Look for skipped coverage, flaky tests and trivial assertions. Give each analysis exclusion a specific reason for review.

## Phase acceptance

Record the branch and commit, acceptance criteria, executed checks and results, outstanding failures, and decisions. Distinguish results from workflow definitions. Use [CI.md](docs/CI.md) to verify check names, branches, schedule and evidence. Stable releases require installation and upgrade instructions. Company deployments are managed separately.
