# ExpedFlow CI and quality gates

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

## ExpedFlow CI configuration

ExpedFlow CI runs on pull requests, pushes to `main`, a daily UTC schedule, release publication and manual dispatch. All events require the core jobs. The three mutation stages, performance and passive ZAP are required for scheduled, release and manual events. Pull requests and pushes expect those five job groups to be skipped. The aggregate gate enforces both the exact job inventory and every required outcome. See the filename transition below for the active dispatch path.

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
3. Require the GitHub Actions check named `quality-gate` from ExpedFlow CI. Historical runs used the Phase B display name. The database, contract, browser, accessibility and storage checks are required by this aggregate. Keep CodeQL alert review enabled where available.
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

Phase D used one regular pull request from `feat/authentication-users-d` to `main`. Core CI ran automatically and the historical Phase B workflow supplied extended checks by manual dispatch on the same candidate.

The initial candidate `4654bc4` failed `backend-quality` because the injected password broker interface does not declare the token deletion method. The follow-up uses Laravel's documented facade. Review only the run for the final head when deciding acceptance. [Initial Phase D core run](https://github.com/alexbonavila/ExpedFlow/actions/runs/45972628346)

The owner accepted Phase D and authorized Phase E from `6d8eef9c00f683f2e48e17ff80a18e6019702c11`. The [post-merge push 45997572529](https://github.com/alexbonavila/ExpedFlow/actions/runs/45997572529) passed on that exact commit.

## Phase E review evidence

Phase E uses `feat/authorization-roles-e` and [PR #12](https://github.com/alexbonavila/ExpedFlow/pull/12). The core run passed all 13 required jobs and the original extended run passed all 16 jobs on `dcff07bc1724576a0c294772960abe11bad4ff32`. [Core run 46002765726](https://github.com/alexbonavila/ExpedFlow/actions/runs/46002765726) · [Extended run 46002780640](https://github.com/alexbonavila/ExpedFlow/actions/runs/46002780640)

The real scheduled event is verified above. A published-release event and independent CodeQL alert inventory review remain operational follow-up. A passing CodeQL workflow is evidence of scanner execution.

## Phase E.1 distributed mutation testing

### Diagnosis and reference

Phase C took 23 seconds for mutation execution, Phase D took 1,012 seconds and Phase E took 2,464.69 seconds. Phase E generated 836 mutations in 51 files, with 743 detected, 80 surviving and 13 uncovered for 88.88%. The reference core run took 2 minutes 11 seconds and extended CI took 43 minutes 3 seconds. The hosted mutation job occupied 42 minutes 50 seconds including setup. [Reference core](https://github.com/alexbonavila/ExpedFlow/actions/runs/46002765726) · [Reference extended](https://github.com/alexbonavila/ExpedFlow/actions/runs/46002780640)

Pest 5.3.7 and mutation plugin 5.0.2 run an initial coverage suite, then spawn a PHP/Pest subprocess with the covering tests for each covered mutation. Child runs repeat framework startup, guarded schema refresh and data setup. In the existing local Phase E JUnit report the identity concurrency group used 22.45 seconds of test time and the MySQL group used 10.79 seconds. The concurrency group resets and seeds its schema repeatedly for independently committed transactions. The installed native `--parallel` option supplies worker tokens, while the database guard deliberately fixes one schema and account. Pest test sharding limits which tests run, and does not partition mutation IDs. The existing 128 MB local CLI limit exhausted memory during native generation. Mutation execution now has a 512 MB PHP allowance.

### Distribution and scoring

- The planner asks the installed plugin to generate the full inventory under `app`, `routes` and `bootstrap/app.php` with its default operators. It assigns every portable ID to one worker. The ID hashes the repository-relative path, operator and complete rendered mutant. Each worker's artifact also records Pest's original checkout-specific ID.
- Eight hosted jobs by default, or four by manual selection, start independent MySQL service containers. Each has its own credentials and schema guarded by the existing provisioning and safety checks. Each runs the full initial coverage suite and Pest's original subprocess runner serially. A version-pinned generation subscriber compares the entire native inventory with the plan and selects the assigned IDs.
- Assignments spread each file across runners with deterministic cost tie-breaking. Locally the four-runner plan assigned 209 IDs each with estimated weights of 604–607. The eight-runner plan assigned 103–106 IDs each with weights of 300–304. Those estimates measure distribution. The accepted hosted timings appear below.
- Every result is bound to the commit, mutation configuration, run ID, attempt and exact plan SHA-256. The aggregate checks planned IDs against generated IDs and final outcomes, rejects duplicates, missing reports and invalid values, and computes detected divided by generated over the complete inventory. Shards have no individual minimum. The global gate requires at least 80%.
- Pest's tested, untested, uncovered and timeout classifications are retained in diagnostic artifacts. The adapter checks fresh PHPUnit evidence and process exit status before admitting a result to the gate. It rejects infrastructure failures and unverified timeouts. Pest normally credits timeouts as detected, so timeout investigations require explicit evidence before considering an adapter change.
- The full summary JSON records all results, including survivors and uncovered IDs, wall seconds per worker, combined mutation command runner seconds, slowest worker and worker utilization. Worker utilization is the sum of shard times divided by runner count and longest shard time. For the final comparison, sum the Actions job durations to include setup and infrastructure, and compare the resulting critical path with the two reference runs. Compute end-to-end parallel efficiency as the sequential mutation job time divided by runner count and the elapsed planner-to-aggregate time. Validate totals and classifications against 836/743/80/13 and report any differences. The original run retained aggregate text and no complete per-mutation ID ledger. Equivalent generation can be checked against the unchanged Phase E sources with the pinned generator. Historical per-ID classifications cannot be reconstructed from totals alone.

### CI efficiency and filename transition

Composer caches only locked distribution archives. npm caches only registry downloads. Keys include the lockfile, platform, selected runtime and relevant setup configuration. Each job still installs from the lockfile. The successful Vite build uploads compiled assets once for the four same-run browser, performance and passive security consumers. Each browser job still installs Playwright and exercises Chromium, Firefox and WebKit against its own MySQL service. No test outcome is cached.

The display name and concurrency prefix are now `ExpedFlow CI` and `expedflow-ci`. The required check retains the exact name `quality-gate`. Phase E.1 retains `.github/workflows/phase-b.yml` in PR #12 so GitHub can dispatch an existing default-branch workflow on the candidate ref. The obsolete Phase B feature-branch push trigger is removed.

For the Phase F candidate, dispatch `gh workflow run phase-b.yml --repo alexbonavila/ExpedFlow --ref feat/clients-expedients-f -f mutation_shards=8`. The default for manual, scheduled and release events is eight. Manual dispatch also accepts four. The PR triggers core CI. Record both run URLs and the exact SHA, then leave the runs to finish independently. After a failed partial rerun, start a fresh dispatch so all mutation artifacts share one attempt.

After owner-approved Phase F integration, complete the filename transition in an authorized follow-up by running `git mv .github/workflows/phase-b.yml .github/workflows/ci.yml` and updating the current dispatch instructions. Keep the workflow definition and job name `quality-gate` during that move. Check the renamed workflow's PR gate, then after its authorized integration dispatch `gh workflow run ci.yml --repo alexbonavila/ExpedFlow --ref main` and verify the new workflow's extended job results. Preserve historical workflow and run references.

The owner accepted E and E.1 after [PR #12](https://github.com/alexbonavila/ExpedFlow/pull/12) integrated at `b16f3329813e884c7b2c4ec1a222cfc9c05d8620`. The [E.1 core](https://github.com/alexbonavila/ExpedFlow/actions/runs/46011773824), [four-runner extended](https://github.com/alexbonavila/ExpedFlow/actions/runs/46011765743), [eight-runner extended](https://github.com/alexbonavila/ExpedFlow/actions/runs/46016882808) and [post-merge main](https://github.com/alexbonavila/ExpedFlow/actions/runs/46019951613) runs passed. Both extended runs reproduced all aggregate counts and 88.88%. Four-runner logs also matched all 93 non-detected native IDs and classifications. Extended elapsed times were 20m 47s with four runners and 12m 33s with eight. The eight-runner aggregate recorded 631.4 seconds for the longest shard and 3,554.2 mutation-command runner-seconds. This evidence supports the eight-runner default.

## Phase I CIFS validation

The existing storage-integration job runs its original shell smoke and a dedicated production-adapter suite with guarded disposable MySQL and loopback CIFS. It records stage diagnostics in the existing storage-integration artifact. The mount-specific suite is outside normal coverage and mutation runner invocations, which continue to cover application storage logic with deterministic filesystem fixtures. Phase I publishes one PR for core CI and dispatches the same workflow on the branch head with mutation_shards=8. Record both run IDs and compare their head SHA to the published candidate. The required quality gate and the workflow filename are unchanged.

### Phase I mutation inventory correction

The original Phase I planner found one repeated portable identifier among 2,448 Pest entries. Pest 5.0.2 applied RemoveArrayItem independently to the two null values in SambaAdapter::state on line 152. The distinct AST nodes begin at byte offsets 6,108 and 6,114. Both changes render the same modified source and coverage line range. The package also issued the same native ID because its hash uses the checkout path, operator and rendered PHP.

The planner and workers now share a version-pinned adapter. For a native collision it replays the affected operator through Pest's own visitor, reconciles the original membership, multiplicity, rendered source and line ranges, then distinguishes the matching mutations by original AST byte offset. The offset is added to the portable identity and to the native worker identity for that group. Every generated mutation remains in the denominator. The worker runs the original mutated source, and the merger continues to check unique identities and fresh evidence for the same candidate and run attempt.

Local four- and eight-worker plans both contained 2,448 unique assignments across 85 files. Four workers each received 612 mutations. The eight-worker distribution was 305, 305, 307, 307, 307, 307, 305 and 305. The repeated eight-worker plan was byte-identical. A targeted native run detected both variants, with separate outcomes and no infrastructure failures. The tooling tests exercise the same-line and separate-line cases, independent checkout paths, matching worker generation, and rejection of missing, unexpected and duplicate results. Hosted acceptance requires the complete fresh eight-worker run, the existing 80% score gate and core validation with the real CIFS job.

GitHub reported PR #16 as merge-conflicted after main advanced during the correction. The branch stays on the accepted Phase F baseline for this correction. Automatic core PR CI requires a mergeable PR. The extended dispatch still runs against the branch candidate and provides independent hosted storage and mutation evidence.

### Phase I storage lock mutation correction

Extended run 46078763449 timed out on four mutations of the CaseStorageLock failure classification. IdenticalToNotIdentical changed the comparison to !== 1, DecrementInteger to === 0, IncrementInteger to === 2, and TernaryNegated negated the original predicate. Each mutation left the exclusive, nonblocking acquisition request intact.

A bounded replay of each exact generated source reached an exclusive lock in the fixture process and a blocking shared lock in the Pest subprocess. The pinned Pest mutation file wrapper replaces the operation passed to flock with LOCK_SH. The holder waited for input that the blocked test could only send after the retry returned. The four bounded local replays ended at 14 seconds with empty JUnit reports. Their hosted timeout evidence was correctly rejected.

The mutation subprocess bootstrap opens CaseStorageLock lease files through PHP's native file wrapper so flock receives the original flags and reports the actual contention result. The adapter restores Pest's wrapper immediately after opening the lease so mutant source loading continues. The ordinary application and the generated mutation inventory keep the same code. The contention test supervises holder readiness and cleanup, and the holder releases its lease independently if its four-second input deadline expires. It checks the busy response during contention, the holder's successful release and the retry result after the release. Related regressions cover acquisition, callback failure and transfer failure. The existing mutation evidence validator still requires fresh subprocess XML for detections and rejects timeouts.

The six initial test warnings in the hosted run were all reported against SambaMountTest before mutation generation. A targeted PHP event trace reproduced a transient readlink warning in the shared test fixture. Its procfs glob included the listing descriptor, which closed before the subsequent readlink. The fixture now keeps the descriptor directory open during enumeration. The six-test targeted run passed and the fixture no longer emitted that warning. The hosted logs identify the tests but omit the individual warning messages.

The four exact variants each produced a fresh HTTP assertion failure with exit 1 in 1.66–1.82 seconds after the correction. A replay with the original source confirmed native LOCK_EX and LOCK_NB behavior and passed after the holder released the lease. A deliberately blocking replay failed at the holder deadline and left no live holder. The focused Pest run used the samba-lock group and evaluated all 38 lock mutations with 30 detected, eight surviving and zero timeouts or invalid evidence.

The final guarded regression passed 267 tests and 2,620 assertions with 97.77% line coverage (3,117 of 3,188) and 100% measurable type coverage. Composer validation, Pint, Larastan level 8 and all 16 mutation-tooling tests passed. The four- and eight-worker plans match the prior inventory of 2,448 mutations across 85 files and assign every mutation exactly once.
