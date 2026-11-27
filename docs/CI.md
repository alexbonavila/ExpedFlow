# CI and quality gates — Phase B

## Objective

Configure GitHub Actions after establishing Laravel and the repository in A and before any business migrations in C. Automate PHP, frontend, MySQL, security and E2E checks. Corporate installations are deployed separately. Demonstrate that valid code passes and deliberate errors are rejected.

## Proposed workflows

| Job / workflow | Checks | Trigger |
|---|---|---|
| backend-quality | Laravel Pint `--test`, PHPStan + Larastan, Composer validation and type checking | PR, push to main, nightly |
| backend-tests | Pest/PHPUnit unit, feature, integration, architecture, regression and coverage checks | PR, push to main, nightly |
| database-tests | Real MySQL, migrations, rollback, models, concurrency and constraints | PR, push to main, nightly |
| frontend-quality | ESLint, Prettier check, vue-tsc | PR, push to main, nightly |
| frontend-tests | Vitest, Vue Test Utils | PR, push to main, nightly |
| api-contract | Reproducible OpenAPI and validation of actual responses | PR, push to main, nightly |
| security | Composer audit, npm audit, Gitleaks and CodeQL as applicable | PR/push and scheduled according to the tool |
| build | `composer install` from the lockfile and a Vite build with npm ci | PR, push to main, nightly |
| e2e | Playwright, MySQL, temporary server and production build in Chromium, Firefox and WebKit | PR, push to main, nightly |
| accessibility | axe-core on actual harness or application pages | PR, push to main, nightly |
| storage-integration | Disposable Samba, a smoke test and real tests as they become available | PR, push to main, nightly |
| mutation | Pest Mutation with a report and an agreed threshold | Nightly and release |
| performance | k6, a performance budget and a synthetic dataset | Nightly and release |
| dynamic-security | OWASP ZAP against an authorized temporary instance | Nightly and release |
| quality-gate | Explicit aggregate of mandatory results | PR, push to main |

Configure Dependabot for Composer, npm and Actions. Its update checks are separate from tests. Choose a simple number of YAML files and jobs and give jobs stable names. Keep mandatory jobs visible so path filters cannot leave the gate pending or misleadingly green. Expensive checks may run outside PRs, but configure and test them and require them for B or release acceptance as applicable. Verify protection before reporting it as configured.

## Environment and reproducibility

- Pin compatible PHP, Laravel, Composer, Node, npm, MySQL, browser, dependency and Actions versions and track lockfiles. Use supported versions and check dependencies during A/B. Use Linux runners and a MySQL service with a health check and CI-only credentials. Isolate each worker's database where applicable.
- Install dependencies with `composer install` and `npm ci`. Use caches only to accelerate installation. Prepare an ephemeral APP_KEY, test configuration and a test database, and migrate from scratch. Keep company and production secrets and infrastructure out of CI.
- Use a real E2E server with HTTP readiness checks and compiled assets. Run tests in real browsers. Mock HTML tests alone are insufficient. Capture traces, screenshots and logs on failure. Validate the reference Apache configuration in Q/R.
- Use an ephemeral SMB service or compatible isolated runner with least privilege and synthetic data. If a hosted runner cannot mount the share, implement and verify a real CI alternative before accepting the control. Keep any fake Storage tests separate from this check.
- Provide local equivalents for CI commands. Proposed Composer scripts are `test`, `test:coverage`, `analyse` and `lint`. Proposed npm scripts are `lint`, `typecheck`, `test`, `build` and `test:e2e`. Document and test the final names. Document any required manual steps.
- Minimize GitHub permissions and keep privileged secrets out of `pull_request` runs from forks. Avoid executing untrusted code with write privileges through `pull_request_target`. Pin actions to trusted revisions, set timeouts and use concurrency controls to cancel obsolete runs. Limit artifact retention and keep secrets out of artifacts.

## Gate and protection

Protect `main` with a ruleset or branch protection that requires PRs and checks and blocks routine bypass and force pushes. Define the aggregate gate with `needs` and evaluate it even after failures. Fail if any mandatory dependency fails, is cancelled or is unexpectedly skipped. Keep `continue-on-error` out of blocking gates. Choose a human review policy that works for the number of contributors. Report independent approval only when it occurred.

The proposed thresholds are at least 90% PHP line coverage, 100% PHP type coverage, a mutation score of at least 80%, zero test failures, zero formatting, static analysis or build errors, zero secrets and zero unresolved High or Critical vulnerabilities. Validate applicability and values in B and document justified exclusions, scope and exact commands. Obtain authorization before lowering a requirement. Evaluate and resolve dependency alerts before the gate or release according to the documented policy and keep them visible. Cover critical scenarios through explicit cases. An empty report fails the criterion.

## Adversarial CI validation (mandatory in B)

Use unmerged temporary branches and controlled, reversible changes. Keep real secrets out of the tests. Verify that the workflow and gate fail for the expected reason and that a PR with the failure cannot merge through the normal process. Revert the changes and verify a final green run.

| Deliberate failure | Expected check |
|---|---|
| False unit assertion | backend-tests |
| PHP type error | backend-quality |
| PHP formatting violation | Pint |
| Invalid migration | database-tests |
| Architecture rule violation | Pest Architecture |
| JSON response that differs from the schema | api-contract |
| Vue type or compilation error | frontend-quality/build |
| False component assertion | frontend-tests |
| False browser E2E assertion | e2e |
| Detectable accessibility violation | accessibility |
| Synthetic value recognized by a test secret rule, using a dummy credential | Gitleaks |
| Coverage below the threshold | coverage gate |
| Mandatory job cancelled or skipped | quality-gate |
| Test Samba share unavailable | storage-integration detects the failure and prevents writes to a local fallback |

Save references to runs and outcomes. Keep deliberate faults, detectable synthetic credentials and unnecessary temporary branches out of main. Exercise nightly and release checks through `workflow_dispatch` or an equivalent so they can be tested ahead of the schedule. Verify that runs generate reports and make failures visible.

## Acceptance of B

Run all applicable checks on a specific commit. Use real PHP, MySQL, Vue, browser, contract and security harnesses. Document local commands, verify scheduling and branch protection, record adversarial test evidence and verify a clean, green main branch. Document categories that lack functional logic and the tests deferred with them. Start C only after resolving infrastructure blockers and demonstrating every mandatory control.

## Phase B workflow configuration

`phase-b.yml` runs on pull requests, pushes to `main` and the Phase B feature branch, a daily schedule, release publication and manual dispatch. The schedule follows GitHub's UTC cron. All events require the core jobs. Scheduled, release and manual events also require mutation, performance and passive ZAP checks. On PR and push events the gate expects those three jobs to be skipped. The feature-branch push trigger allows CI evidence while authenticated PR access is unavailable. The aggregate gate fails if any required job fails, is skipped or is cancelled, or if the configured job list differs from the gate's list.

The backend test job runs each Pest suite once and captures line coverage, type coverage and JUnit. The `database-tests` and `api-contract` jobs verify the nonempty Integration and Contract suite outcomes from that report. Each job that uses MySQL gets a separate service. It provisions a random password for the restricted testing account and passes values to later steps through the GitHub environment file with masking. Keep all credentials and reports scoped to synthetic data. Frontend and browser jobs install from the npm lock. The E2E and accessibility jobs each install all three browser engines. CodeQL analyzes JavaScript and TypeScript in a dedicated job. Laravel PHP gets Larastan, Pest and the dependency audit.

The branch ruleset must require PRs and the observed `quality-gate` status, prevent force pushes and routine bypass, and use a feasible review policy for the repository's contributor count. Verify that code scanning is enabled for the repository and review the alert results independently of workflow completion. Select the code scanning protection rule for unresolved findings at the required severity where the GitHub plan supports it. Scheduled and release results remain part of phase acceptance even when a PR gate passes. Manual dispatch requires the workflow file on the default branch. The owner controls ruleset changes and integration.

## Deliberate failures observed locally

The following runs used the isolated `validation/phase-b-controls` checkout branched from `2c934ab` and a separate test MySQL container. Each defect was restored after its check. For the Gitleaks run the synthetic fixture was committed and reverted inside that local checkout. The synthetic commits were never published. The feature branch was published, but GitHub's API rejected the PR request and run queries with HTTP 403.

| Deliberate fault | Local result |
| --- | --- |
| Incorrect assertion in the database guard unit test | Pest exited 1 |
| Invalid value passed to a PHP string function | Larastan exited 1 with the argument type error |
| Invalid PHP spacing | Pint exited 1 and identified the affected construct |
| Exception in a baseline migration | The guarded MySQL suite exited 2 |
| Debug dump in application code | Pest Architecture exited 1 |
| Response status value outside the OpenAPI enum | The response validator rejected it and Pest exited 2 |
| Number-typed Vue ref initialized with a string | vue-tsc exited 2 |
| Changed confirmation in the Vue component | Vitest exited 1 |
| Incorrect page title expectation | Playwright Chromium exited 1 |
| Button with no accessible name | The Chromium axe scan reported `button-name` and exited 1 |
| Synthetic Gitleaks sentinel | Gitleaks exited 1 and identified the sentinel rule |
| Unit-only coverage below the 90% gate | The assertions passed, and the coverage minimum caused exit 1 |
| Required result failed, cancelled or skipped | The local aggregate script returned 1 for each outcome |
| Missing extended jobs on a scheduled event | The local aggregate script returned 1 |
| Empty CIFS mount point | The shared copy guard refused the operation and left the underlying directory empty |

The local gate also accepted the expected all-green PR and scheduled result sets. These local inputs exercise the aggregate's logic. The contract-drift push on the temporary validation branch produced a real hosted failure. The backend suite exited 2, the contract check exited 1, and the aggregate exited 1 in [run 45819680432](https://github.com/alexbonavila/ExpedFlow/actions/runs/45819680432). The post-integration acceptance record below tracks PR merge blocking and workflow trigger evidence. Run the full CIFS check on an isolated Linux runner with kernel CIFS and permitted mount capabilities. `bash scripts/testing/storage.sh --check-unmounted` exercises only the shared missing-mount guard.

## Candidate verification and acceptance

The local runtime checks ran against the code at `a8d5ab6`. Composer validation, Pint, Larastan, ESLint, Prettier, vue-tsc, Vite and actionlint passed. Pest executed 28 tests with 97 assertions, including four MySQL integration tests and one contract test. Line coverage was 662 of 662 executable lines across 26 source files, and the declaration-coverage report found zero uncovered lines across its 24 scanned files. Vitest passed one component test. Chromium passed the interaction and axe tests, and the production harness request returned HTTP 404. The workflow's MySQL provisioning script authenticated correctly in a local reproduction of the Actions service.

Before integration, the repository's public branch inventory reported no legacy branch protection and an empty ruleset list. The post-integration record below supersedes that historical observation. Authenticated GitHub API operations returned HTTP 403 through the cloud proxy. The feature branch push is authorized through the Git proxy. Branch rule administration and draft PR creation require authenticated access. Before integration, open the PR, verify the observed required check name and enforce it through the `main` ruleset with required PRs, force-push blocking and a feasible review count. Run and record scheduled, manual and release checks when the workflow is present on the default branch. Use an authorized owner review for integration.

The first two feature-branch runs ([45818569120](https://github.com/alexbonavila/ExpedFlow/actions/runs/45818569120) and [45819062188](https://github.com/alexbonavila/ExpedFlow/actions/runs/45819062188)) passed the backend, frontend, dependency audits, CodeQL execution, three-engine E2E and accessibility jobs. The container-side CIFS check returned exit 2 in both runs and the required aggregate returned exit 1. The push policy skipped mutation, performance and ZAP as expected. The second run used Composer 2.11.2, resolving the first run's warning about the upstream Composer 2.8.8 binary.

The revised hosted workflow passed every core job, including the real CIFS mount and aggregate, in [run 45819539665](https://github.com/alexbonavila/ExpedFlow/actions/runs/45819539665) and in the subsequent [run 45820590297](https://github.com/alexbonavila/ExpedFlow/actions/runs/45820590297) at `e791611`. The separate extended [push verification run 45819755815](https://github.com/alexbonavila/ExpedFlow/actions/runs/45819755815) exercised the same performance and passive security steps used for the scheduled jobs. Performance passed. Mutation exited 1, the passive scan exited 2, and the aggregate exited 1. The matching local mutation run measured 75 of 647 detected (11.59%), below the 80% minimum. The temporary branches were restored after both validation runs. The post-integration record below tracks the actual events.

The restricted behavioral mutation scope and the focused security disposition are documented in [TESTING.md](TESTING.md) and [SECURITY.md](SECURITY.md). The 80% mutation minimum and Low ZAP warning threshold remain in effect. Feature-branch pushes verify the core workflow. The temporary extended validation branch exercises all extended jobs through a push. The post-integration record distinguishes actual events from the temporary push checks. Domain models, business APIs, permission scenarios and operational storage integration remain in their assigned later phases.

### Repository owner integration procedure

1. Open the comparison from `feat/testing-ci-infrastructure` into `main` and create the PR. Match the advertised head SHA to the candidate verification. Wait for the actual PR workflow.
2. In GitHub, open **Settings → Rules → Rulesets** and create an active branch ruleset targeting the default branch `main`. Require a pull request before merging, require status checks, require the branch to be up to date, and block force pushes and deletion. Keep the bypass list empty. Configure reviews in line with repository ownership.
3. Require the GitHub Actions check named `quality-gate` from the `Phase B` workflow. GitHub may display it as `Phase B / quality-gate`. The database, contract, browser, accessibility and storage checks are required by this aggregate. Keep CodeQL alert review enabled where available.
4. Confirm the rule applies to `main` and verify an actual failing PR check prevents integration. Record the PR workflow and rule evidence, then obtain explicit owner authorization before integrating.
5. After authorized integration, verify the protected `main` push. Run the manual extended workflow from the default branch, observe the actual scheduled workflow and record release-event verification when an authorized release occurs.
6. Review both `validation/` branches against their recorded implementation base. Preserve run URLs and any needed review history. The temporary trigger and deliberate fault can be reverted on their branches. Retire the remote branches only after owner authorization.

## Post-integration Phase B acceptance audit

The implementation was merged through [PR #1](https://github.com/alexbonavila/ExpedFlow/pull/1). The audited default-branch commit is [`f33b90fe196023ffd97568bfbf8f40921c3a83e3`](https://github.com/alexbonavila/ExpedFlow/commit/f33b90fe196023ffd97568bfbf8f40921c3a83e3). The former implementation branch has been deleted.

| Event | Evidence | Result |
| --- | --- | --- |
| Pull request #1 | [45830722868](https://github.com/alexbonavila/ExpedFlow/actions/runs/45830722868) | All core jobs and the aggregate passed. |
| Push to `main` at `f33b90f` | [45832019557](https://github.com/alexbonavila/ExpedFlow/actions/runs/45832019557) | All core jobs and the aggregate passed. |
| Manual dispatch on `main` at `f33b90f` | [45832795169](https://github.com/alexbonavila/ExpedFlow/actions/runs/45832795169) | All 16 jobs passed, including mutation, performance, dynamic security and the aggregate. |
| Push to `main` at `5436acb` | [45951197131](https://github.com/alexbonavila/ExpedFlow/actions/runs/45951197131) | GitHub reported success after English documentation integration. |
| Daily schedule | [Scheduled run 45986529932](https://github.com/alexbonavila/ExpedFlow/actions/runs/45986529932) | The actual `schedule` event passed all 16 jobs on `942e5cdc416947a586ac4167a9a3c5a76197e100`. |
| Published release | [Release-event history](https://github.com/alexbonavila/ExpedFlow/actions/workflows/phase-b.yml?query=event%3Arelease) | No release-triggered run was visible on November 27, 2026. The workflow is configured for `release.published`, and manual dispatch already exercised the extended jobs. Deferring event verification to the first owner-authorized release requires owner approval. An owner-approved disposable repository could verify the trigger mechanics for that repository. |

[Ruleset 28167262](https://github.com/alexbonavila/ExpedFlow/rules/28167262) is active and targets the default branch. Its public rules require a PR, the GitHub Actions check `quality-gate` and an up-to-date branch. They block deletion and force pushes. The public ruleset response independently confirms `bypass_actors: []` and `current_user_can_bypass: never`.

GitHub listed the Phase B workflow as active on November 27, 2026. The workflow is present on the default branch and specifies `17 2 * * *` in UTC. GitHub documents that scheduled events may be delayed or dropped. The history did not show why the slot had no visible run. The schedule was kept as configured. [GitHub schedule documentation](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#schedule)

The historical [contract-drift run 45819680432](https://github.com/alexbonavila/ExpedFlow/actions/runs/45819680432) proves that the aggregate rejects the defect. It predates this ruleset and ran on a push.

The temporary `validation/phase-b-protection-audit` branch was published from `5436acb`. Commit [`5936f80`](https://github.com/alexbonavila/ExpedFlow/commit/5936f8019aea41c2e4fba07b9334e8539ffb975e) changes only the welcome-page title assertion in `tests/Feature/ApplicationTest.php`. The targeted Pest run executed one test, failed on the altered title and exited 1. The initial cloud environment could not create the PR because its authenticated API returned HTTP 403. The authorized repository connection subsequently created [PR #6](https://github.com/alexbonavila/ExpedFlow/pull/6). The actual [PR run 45952193383](https://github.com/alexbonavila/ExpedFlow/actions/runs/45952193383) failed `backend-tests` on the deliberate title assertion (1 failed, 34 passed, 147 assertions) and failed `quality-gate`. At head `5936f8019aea41c2e4fba07b9334e8539ffb975e` and base `5436acb099c77bbdeea125a440b2f38532abe376`, GitHub returned `mergeable: true` and `mergeable_state: blocked`, confirming effective merge blocking without a content conflict. No merge was attempted.

The failure and blocked state are preserved in the PR conversation. The original test was restored byte-for-byte in [`d448383`](https://github.com/alexbonavila/ExpedFlow/commit/d44838386c1be196f5894b37198dc35c681f436a). The [recovery run 45952671625](https://github.com/alexbonavila/ExpedFlow/actions/runs/45952671625) passed and [PR #6](https://github.com/alexbonavila/ExpedFlow/pull/6) was closed without merging.

The temporary validation branches were absent from the remote at the start of Phase C. The extended branch at `d2a81b2` matched the merged implementation parent `3a66e5b`. The gate-runtime branch at `ea1952b` matched its original base `4b9c946` after the revert. Both base commits are ancestors of `main`. Preserve [extended validation run 45826375945](https://github.com/alexbonavila/ExpedFlow/actions/runs/45826375945) and [contract-drift run 45819680432](https://github.com/alexbonavila/ExpedFlow/actions/runs/45819680432) as the evidence.

### Closeout and operational follow-up

The owner requested repository cleanup and final Phase B closeout on November 27, 2026. [PR #7](https://github.com/alexbonavila/ExpedFlow/pull/7) passed [run 45952196316](https://github.com/alexbonavila/ExpedFlow/actions/runs/45952196316) and was merged at `5f06ab87461bfe719249c94353f8f1405a68c8d8`. The corresponding [main run 45952681738](https://github.com/alexbonavila/ExpedFlow/actions/runs/45952681738) passed. [PR #8](https://github.com/alexbonavila/ExpedFlow/pull/8) passed [run 45952822548](https://github.com/alexbonavila/ExpedFlow/actions/runs/45952822548). The [main run 45955325050](https://github.com/alexbonavila/ExpedFlow/actions/runs/45955325050) passed at the Phase C starting commit `430c9a4`.

The owner accepted Phase B for progression to Phase C from `430c9a4`. Published-release execution and independent review of the CodeQL alert inventory remain open operational follow-up. Retain the existing triggers and gates. The prior manual extended run verified the shared jobs. Record the first authorized published-release run and the GitHub Security inventory review when available. See [SECURITY.md](SECURITY.md) for the dependency updater issue.

## Integrated Phase C

The owner accepted Phase C after integration at `942e5cdc416947a586ac4167a9a3c5a76197e100` through [PR #10](https://github.com/alexbonavila/ExpedFlow/pull/10). The [core run 45959448962](https://github.com/alexbonavila/ExpedFlow/actions/runs/45959448962), [extended run 45959904640](https://github.com/alexbonavila/ExpedFlow/actions/runs/45959904640) and [main run 45960606761](https://github.com/alexbonavila/ExpedFlow/actions/runs/45960606761) each passed. The published release event and independent CodeQL alert inventory review remain operational follow-up.

## Phase D candidate delivery

Use one regular pull request from `feat/authentication-users-d` to `main`. The existing **Phase B** workflow runs core checks automatically on the PR. Select the same branch from **Actions → Phase B → Run workflow** to exercise the extended mutation, dynamic security and performance checks on the candidate. Match both runs to the reviewed head SHA. Record the candidate and its actual run conclusions in the PR and proceed only after owner authorization.

The initial candidate `4654bc4` failed `backend-quality` because the injected password broker interface does not declare the token deletion method. The follow-up uses Laravel's documented facade. Review only the run for the final head when deciding acceptance. [Initial Phase D core run](https://github.com/alexbonavila/ExpedFlow/actions/runs/45972628346)

The owner accepted Phase D and authorized Phase E from `6d8eef9c00f683f2e48e17ff80a18e6019702c11`. The [post-merge push 45997572529](https://github.com/alexbonavila/ExpedFlow/actions/runs/45997572529) passed on that exact commit.
