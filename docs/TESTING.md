# Testing strategy

## Objective of B

Set up the tools, configuration, environments, reproducible commands and CI execution for applicable test categories before implementing any business functionality. Write minimal real tests for the base project and test harnesses. Add domain tests alongside their implementation. Avoid empty cases, permanent skips and artificial coverage. There is no requirement to use duplicate tools or test unimplemented functionality.

## Test matrix

| Category | Preferred tool | Initial evidence in B / later expansion |
|---|---|---|
| Unit | Pest/PHPUnit | Assert actual application or baseline behavior. Domain rules follow in C–K |
| Feature / HTTP | Laravel HTTP Testing | Base route, response and error. API flows follow in D–K |
| Integration | Pest + Laravel | Start the container and real services |
| MySQL / migrations | Laravel DB Testing | migrate/rollback/migrate, constraints and a real MySQL connection |
| Models / relationships / factories / seeders | Laravel + Pest | Verify existing base elements. Verify the full schema in C |
| Transactions / concurrency | Pest + independent MySQL processes or connections | Demonstrate isolated commit, rollback and contention. Numbering and Kanban follow in F/H |
| API / contracts | HTTP assertions + OpenAPI validator | JSON from a minimal endpoint and a verifiable schema. v1 contracts follow in K |
| Authentication / authorization | Laravel, Sanctum, Policies | Test harness and baseline guard. Sessions, tokens, CSRF and roles follow in D/E |
| Validation / middleware | Laravel HTTP Tests | Real validation error from the harness. Add each resource's rules when implemented |
| Artisan / scheduler | Laravel Console Tests | Real baseline command. Initialization and cleanup follow in D/J |
| Events / listeners / jobs / queues | Laravel fakes + integration where used | Verify harness capabilities. Test events and jobs when they are actually used |
| Mail / recovery | Mail/Notification fake | Verify simulated delivery without production SMTP. Recovery flow follows in D |
| Filesystem | Storage::fake + integration | Write to and read from a test disk. Real Samba follows in I |
| Real Samba | Disposable SMB share on a compatible runner | Prepare the service and fixtures and smoke test an isolated mount and copy without real data |
| Localization | Laravel/Pest | Verify that es/ca/en exist, keys match and fallback works. Test each phase's messages |
| Architecture | Pest Architecture | Apply a real rule, such as forbidding debug calls in first-party code or controlling dependencies |
| Regression | Pest/Vitest/Playwright | Add cases with each fix |
| Snapshots | Pest or a frontend tool | Cover only justified deterministic outputs and review changes explicitly |
| Coverage / types | Pest + coverage driver and type coverage | Reproducible reports and a threshold calibrated against first-party code |
| Mutation | Pest Mutation | Run against testable first-party logic. Framework mutations are outside the requirement |
| Frontend unit / component / integration | Vitest + Vue Test Utils | Minimal harness component, interaction and a real failure case |
| E2E | Playwright Test | Test real navigation and observable outcomes in a browser against the server and built assets. Expand by phase |
| Browser compatibility | Playwright Chromium, Firefox, WebKit | Smoke tests on all three engines and a feature matrix |
| Accessibility | axe-core + Playwright | Scan a real harness page and exercise keyboard, manual and automated UI flows |
| Performance | k6 | Smoke test with a measurable budget and without saturating infrastructure. Realistic data load follows in Q |
| Dynamic security | OWASP ZAP baseline / negative HTTP tests | Scan an authorized temporary environment. Authentication, CSRF and IDOR follow in functional phases |

Numbering and concurrency tests require real MySQL. Verify SMB storage in a real isolated environment. Fakes are useful at boundaries, but these integration checks require the named services. SQLite is optional for engine-independent cases. Include MySQL in integration coverage. Use simulated mail and synthetic files.

## Proposed organization

```text
tests/
  Unit/
  Feature/              # Authentication, Authorization, Api, Console, Validation, Localization
  Integration/          # Database, Migrations, Models, Factories, Seeders, Concurrency, Storage
  Architecture/
  Contract/
  Regression/
  Fixtures/
  Support/
  Pest.php
  TestCase.php
frontend-tests/        # Unit, Components, Integration
e2e/                   # specs, fixtures, support
performance/
security/
```

Create directories and suites when they serve a real purpose. Configure discovery for every suite, including non-default ones, and confirm CI includes them all. Share one command source between local development and CI through the proposed Composer and npm scripts. Configure `.env.testing` with a disposable database, guards against production and no real secrets. Isolate the database or schema per parallel worker. Explicit concurrency tests use independent connections to the same test database. Avoid enclosing those tests in a transaction whose changes the other connections cannot see.

## Mandatory cases by domain

- C covers migrations from scratch and rollback, indexes, constraints, relationships, idempotent seeding, factories, language, uniqueness, date-only values and rejection of time without a date. Test model soft deletion and restoration where in scope.
- D/E covers web login, mobile token, logout and revocation, recovery, blocked public registration, rate limits, CSRF, 401/403, privilege escalation and idempotent administrator initialization. Ordinary users can restore records and must be denied hard deletion even through direct API requests. Verify that roles, permissions and policies agree.
- F covers unique clients and references, optional represented clients, nine-digit codes with leading zeroes, 001/999/1000, year rollover and time zone, unique immutable type codes, simultaneous creation without duplicates and preservation of assigned numbers after deletion.
- G covers multiple assignees without duplicates, nullable date with optional time, editing other users' comments with the actor audited, date-free checklists, and case restoration that keeps separately deleted tasks deleted.
- H covers the shared catalog, explicit status selection, prevention of deleting in-use statuses including references from soft-deleted records, independent template copies with checklists and descriptions, unique tags and color, private hiding with the full list still available, new cases at the bottom, moved cases at the bottom for other users, personal ordering, shared task ordering and concurrency.
- I covers correct recursive copies, missing mounts, missing source folders, collisions, partial failures, permissions, idempotent retries without duplication, valid uploads, and rejection of traversal, oversize files and symlinks. Verify that Samba files cannot be deleted or listed, incidents persist and responses reflect the real outcome. Verify that the local directory under an unmounted share stays free of accidental writes.
- J covers actor, date, differences, relationship and comment changes, secret redaction, one-year audit deletion and retention at time boundaries, and the scheduler. Verify useful incidents separately from auditing.
- K covers endpoints and permissions, JSON including errors, unpaginated responses, synchronized OpenAPI, allowed filters and sorting, and exclusion of sensitive fields.
- L–P covers E2E login, simulated recovery, administrator and user flows, clients, cases, templates, two-user Kanban, trash and restoration, multiple tasks, comments and checklists, all-day and timed calendar events, CSV, languages and incidents. Keep Samba browsing out of the UI.
- Q/R covers clean installation and upgrades, browsers, keyboard use, accessibility, load, security, an Apache smoke test and reference production configuration with reproducible documentation.

## Coverage and gates

Propose initial targets for validation in B of at least 90% first-party PHP line coverage, 100% measurable PHP type coverage, at least 80% mutation score, execution of 100% of defined critical scenarios, zero failures or analysis errors and zero unresolved High or Critical vulnerabilities. Track critical scenario execution separately from line coverage. Define denominators, included paths, the driver, reports and cases without measurable code. Keep complex first-party code in scope so metrics reflect the codebase. Pair mutation and type coverage metrics with meaningful assertions. The numerical thresholds are proposals. Record the final values and reasons in B and require working, nonempty gates before C.

## Evidence

Keep JUnit, coverage and analysis reports, E2E traces and screenshots with synthetic data only, k6 and ZAP results, and redacted logs. Record commands, versions, commit and outcomes. Report only tests that were actually run.

## Phase B inventory and traceability

The cloud checkout matched `origin/main` at `22528be` before branching. The inventory ran on Debian 13 with PHP 8.4.28 (PDO MySQL, mbstring, DOM/XML and cURL), Composer 2.8.8, Laravel 13.44.1, Node 24.22.3, npm 11.19.1, MySQL 8.4.13, Pest 5.3.7 and Pint 1.32.3. Node 24 LTS is selected for local and CI use. The checked registry metadata supports Node 24 for Vite 8, Vitest 5, the Vue Vite plugin, ESLint 10 and Playwright. macOS developers can use Herd's PHP CLI with the same extensions and the pinned Node version. Linux CI also needs browser system libraries and a privileged isolated runner for the disposable CIFS mount.

Laravel Boost 2.10.4 discovered all four checked-in skills and answered MCP initialization and tool discovery over standard input. The Codex CLI reported no registered MCP servers in this session. Boost documentation search returned a proxy 403, so version-sensitive implementation uses the installed framework and package sources. The cloud test schema `expedflow_test` runs in its own MySQL container. Its `expedtest` account can connect to that schema and has no privileges on the development schema.

The inherited Phase A suite ran two tests and Pint passed. GitHub and Laravel remained reachable through native Git and the local runtime respectively. Vite's baseline build failed because the cloud proxy denied Bunny Fonts. Baseline Xdebug 3.4.5 line coverage reported 2 of 6 executable lines (33.3%) across the three files in the inherited `app/` scope. That result includes the scaffold suite. B02 measures the candidate on its declared first-party scope. The proposed minima are 90% lines, 100% types and 80% mutation score. Any shortfall remains visible until the underlying code is covered or the owner approves a revised criterion.

| Category | Selected tool and planned entry point | Job and frequency | Evidence and criterion |
| --- | --- | --- | --- |
| PHP formatting and static analysis | Pint `composer lint`, Larastan `composer analyse` | backend-quality, PR/main/nightly | Check-only exit status, no errors |
| PHP behavior, architecture and coverage | Pest, PHPUnit and Xdebug, `composer test`, `composer test:coverage`, `composer test:types` | backend-tests, PR/main/nightly | JUnit, Clover and type output, selected thresholds and all suites run |
| Database | Pest and MySQL 8.4, `composer test:database` | database-tests, PR/main/nightly | Authenticated test-only database and passing integration suite |
| API contract | OpenAPI schema validation and actual HTTP response validation, `composer test:contract` | api-contract, PR/main/nightly | Valid document and conforming responses |
| Mutation | Pest's included Mutation plugin, `composer test:mutation` | mutation, nightly/release/manual | Mutant counts and at least 80% killed |
| Frontend quality | ESLint, Prettier and vue-tsc, `npm run lint`, `npm run format:check`, `npm run typecheck` | frontend-quality, PR/main/nightly | No formatting, lint or type errors |
| Frontend behavior and build | Vitest, Vue Test Utils and Vite, `npm test`, `npm run build` | frontend-tests and build, PR/main/nightly | Passing component tests and built assets |
| Browser and accessibility | Playwright and axe, `npm run test:e2e`, `npm run test:a11y` | e2e and accessibility, PR/main/nightly | Chromium, Firefox and WebKit outcomes, axe findings and keyboard interaction |
| Supply chain | Composer/npm audits, Gitleaks, CodeQL and Dependabot | security, PR/main/nightly | No unresolved high or critical issues, synthetic secret detection |
| Storage | Isolated Samba server and Linux CIFS mount | storage-integration, PR/main/nightly | Fixture I/O and safe failure without local writes |
| Performance and dynamic security | k6 smoke and OWASP ZAP baseline | performance and dynamic-security, nightly/release/manual | Measured budgets and triaged findings |
| Merge gate | GitHub Actions aggregate | quality-gate, PR/main/nightly/release/manual | Required jobs succeed and deliberate failures block integration |

Pest already brings architecture and mutation support. Add the matching type-coverage plugin and Larastan for independent compile-time checks. Use Xdebug as the sole coverage driver. Snapshots are deferred until a stable output needs them. Prefer one frontend runner and one browser runner. GitHub Actions execution, rulesets and the deliberate failure matrix require separate evidence in B05–B07.

### Local PHP and MySQL workflow

Use a dedicated, disposable MySQL 8.4 instance. Create the `expedflow_test` schema and an `expedtest` account with schema-only privileges. With MySQL's default `partial_revokes=OFF`, escape the underscore in the schema privilege grant (`expedflow\_test`); an unescaped underscore is a wildcard. Keep the account password in the ignored `.env.testing`, copied from `.env.testing.example`. Generate a testing application key with `APP_ENV=testing php artisan key:generate --no-interaction`. Use the instance's TCP port and the loopback host. Do not use Laravel's parallel test flag with this single schema.

From the checkout, run `APP_ENV=testing php tests/Support/check-database.php` to verify the effective Laravel connection and authenticated MySQL grants. The integration suite repeats that check before each migration reset. The guard rejects a connection URL, read/write overrides and sockets. Shell variables have precedence, so unset conflicting values when using the testing file. On a failed guard, check the selected environment, account, grant scope and port before retrying.

Run `composer test` for all suites, `composer test:database` for the MySQL suite and `composer test:contract` for the OpenAPI fixture and its actual Laravel response. `composer lint` checks formatting and `composer analyse` runs Larastan at level 8. With Xdebug loaded, use `XDEBUG_MODE=coverage composer test:coverage` and `XDEBUG_MODE=coverage composer test:mutation`. Use `composer test:types` for declaration coverage. Reports go to ignored `coverage/` and `test-results/`. Type coverage reports the directory entries in PHPUnit's source configuration. Larastan and line coverage also check the two explicitly listed bootstrap files.

The B02 cloud run passed all 27 tests with 94 assertions, including a real InnoDB lock timeout and a JSON contract check. The first measured line result was 657 of 657 executable lines across 26 files. Pest type coverage reported 100% across 24 scanned files, and Pint and Larastan passed. These measurements cover the scaffold and harness. Business authorization, resource contracts and domain concurrency remain scheduled for their implementation phases.

### Mutation scope and acceptance

`composer test:mutation` applies the 80% gate to all of `app/`, all of `routes/` and executable application bootstrap in `bootstrap/app.php`. New application classes and routes enter this scope automatically. The command uses the default mutators and reports uncovered mutations. The user model and console route remain in scope alongside custom application behavior.

The excluded paths are declarative `config/` settings, `lang/` dictionaries, the scaffold migrations, factories and seeder under `database/`, and the provider registration list in `bootstrap/providers.php`. The project-specific public storage URL computation now lives under `app/` and is mutated. Keep new executable application behavior under the measured paths or extend the scope when adding a new application entry point. The test runner and fixtures under `scripts/testing/` and `tests/` serve as verification tooling. The existing full-suite, line, type, MySQL integration, concurrency and OpenAPI checks retain their original scope and thresholds.

| Run | Total | Detected | Survived | Uncovered | Score | Gate |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| Original whole-source baseline | 647 | 75 | 567 | 5 | 11.59% | Failed |
| Behavioral scope with regression tests | 35 | 30 | 0 | 5 | 85.71% | Passed |

The five uncovered mutants alter PHP attribute declarations on the scaffold user model. They remain in the denominator. The browser harness tests now reject a request outside testing even if the route was previously registered. The model tests exercise the verification timestamp and withheld authentication fields. Storage URL tests cover custom, missing and non-string values.

### Frontend and browser workflow

Use the Node version in `.node-version`, install with `npm ci`, then run `npm run lint`, `npm run format:check`, `npm run typecheck`, `npm test` and `npm run build`. Vue 3 and its Vite plugin compile the fixture. TypeScript and vue-tsc verify types, ESLint and Prettier verify source quality, and Vitest with Vue Test Utils and jsdom exercises observable form behavior. Instrument Sans comes from the locked Fontsource package, so the build can bundle the same font without an external build-time download.

Install Playwright's Chromium, Firefox and WebKit with `npx playwright install chromium firefox webkit`. On Linux CI add `--with-deps` for browser libraries. Start with compiled assets and the test MySQL configuration above. `npm run test:e2e` and `npm run test:a11y` start their own guarded Laravel process on port 8001 and exercise all three browser projects unless a project is explicitly selected. Axe runs against a visible validation error, and the interaction spec exercises keyboard navigation and submission. Keep port 8001 free. Test failures retain screenshots and traces in `test-results/playwright/`. Playwright also writes an HTML report.

The cloud ran the component test, Vite build, lint, formatting and type checks. Chromium 156 from the system installation passed the browser and axe specifications using `PLAYWRIGHT_CHROMIUM_EXECUTABLE=/usr/bin/chromium`. Requests to the browser harness under `APP_ENV=production` returned HTTP 404. A temporary incorrect confirmation string produced a Vitest assertion failure and was restored. The cloud proxy denied Playwright's browser downloads. Playwright-managed Firefox and WebKit still require an execution environment with access to the browser distribution.

### Performance and SMB smoke checks

With built assets and the testing database available, run `bash scripts/testing/performance.sh`. This Linux Docker check sends three virtual users to the ephemeral Laravel welcome page for five seconds. The baseline observed 56 requests, no errors and a p95 of 266.48 ms. The gate uses a p95 below 500 ms, fewer than 1% HTTP errors and at least 99% successful status and content assertions. A concurrent workload run exceeded p95 at 1.33 s and correctly failed. The subsequent run observed 60 requests, no errors, 120 passing assertions and a p95 of 299.11 ms. Run it on a dedicated runner without simultaneous mutation or image builds. Reports go to `test-results/performance/`.

Use `bash scripts/testing/storage.sh` on an isolated Linux runner with Docker, passwordless sudo, `cifs-utils` and kernel CIFS support. The GitHub-hosted job installs the helper and loads the kernel module. Samba runs in a disposable container with an SMB3 listener bound to loopback. The runner mounts the test share using generated writer and reader accounts, verifies I/O and permissions, stops Samba, then checks that an unavailable share leaves the underlying directory empty. For the guard alone, run `bash scripts/testing/storage.sh --check-unmounted`. macOS developers need an isolated Linux VM or a hosted runner for a real CIFS mount.

The first two hosted attempts used a container-side mount. The managed cloud rejected the container mount capability, and the hosted CIFS smoke returned exit 2. The revised implementation performs the mount on the isolated Linux runner and preserves stage diagnostics. Its loopback Samba server and missing-mount guard were exercised locally. The revised full mount check passed in [GitHub Actions run 45819539665](https://github.com/alexbonavila/ExpedFlow/actions/runs/45819539665).

### Merged baseline verification

The test, coverage and declaration gates passed on `main` at `f33b90f` in [run 45832019557](https://github.com/alexbonavila/ExpedFlow/actions/runs/45832019557). The actual manual workflow at the same commit passed mutation, k6 and ZAP alongside the core tests. The authored local mutation baseline above measured 85.71% against the configured 80% minimum. [Manual extended run 45832795169](https://github.com/alexbonavila/ExpedFlow/actions/runs/45832795169)

## Phase C database verification

Run the existing guarded `composer test:database` suite on a dedicated MySQL 8.4 instance with named time zone data. The suite exercises the entire InnoDB schema from empty, rollback and rerun, and a Phase B upgrade and rollback with existing synthetic users and sessions. Schema-only tests use the same database guard before Laravel refreshes the schema or begins a test transaction. The migration lifecycle and row-lock tests use independent transactions or connections where needed.

- Direct database tests cover mandatory and optional references, restrictive catalogs, dependent deletion, unique names and pivots, code format and range, civil dates and times, task order, boolean values and preference consistency.
- Eloquent tests cover relationships, casts, guarded writes, translations, status seeding after catalog edits or deletions, synthetic factories and retained audit and incident references. The tests exercise persistence and leave business authorization, workflow coordination and audit recording to their assigned phases.
- Two connections verify that an existing annual row can be locked for update. A competing write and a competing first insert each return MySQL lock-wait error 1205. The observer sees only committed data and a duplicate insert after the commit cannot reset the counter. Production allocation and retry behavior remain in Phase F.

| Local Phase C check | Result |
|---|---|
| Full Pest suite on MySQL 8.4.13 | 68 tests and 457 assertions passed, including 37 database tests. |
| Line and type coverage | 99.0% lines and 100% measurable types passed the existing gates. |
| Mutation | 42 of 51 detected, 82.35%. Nine declarative mutations were uncovered. The existing 80% gate and scope were used. |
| Composer validation, platform requirements, Pint and Larastan | Passed. |
| Frontend lint, formatting, type checking, Vitest and Vite build | Passed. |
| Playwright Chromium E2E and axe | One test passed in each run. The Firefox distribution returned HTTP 403 locally. Firefox and WebKit are pending hosted coverage. |
| Existing k6 and passive ZAP checks | k6 p95 was 295.92 ms with 54 requests, 108 passing checks and zero HTTP failures. ZAP passed its report and risk gates. |
| Dependency and secret checks | npm audit passed the High threshold and reported five moderate findings. Gitleaks detected no leaks. The Packagist advisory endpoint returned HTTP 403 for the Composer audit and the result requires hosted verification. |
| Samba harness | The missing-mount guard passed. The full smoke needs the isolated Linux runner with sudo and CIFS helpers. |

The Phase C candidate used automatic pull-request checks and the historical Phase B extended dispatch. See CI.md for the run IDs and current ExpedFlow CI commands.

## Phase D identity verification

Activate the Codex toolchain in each Bash shell before running PHP or project tests. The existing MySQL guard runs before schema resets in the new integration and HTTP suites. HTTP fixtures carry genuine encrypted cookies and CSRF tokens. They use the database session driver and simulate a production application environment for native CSRF enforcement on HTTPS. The contention tests use an independent Laravel process and signal when it reaches a locking query. The parent holds the real MySQL transaction until that signal and releases it without a timed sleep. Do not run the database suites in parallel against one test schema.

| Requirement | Verification |
|---|---|
| Upgrade, rollback and safe defaults | `PhaseDUpgradeTest` upgrades the accepted Phase C schema, preserves the original user row and task assignment, rolls back and migrates again. |
| Explicit administrator | `AdministratorBootstrapTest` covers input policy, ordinary-account collision, reserved role conflicts, a repeatable initializer and rollback on persistence failure. `ConcurrentIdentityTest` exercises a competing initializer. |
| SPA and CSRF | `SessionIdentityTest` exercises the native XSRF cookie, rejection of missing and wrong tokens, real database sessions, cookie attributes, session rotation, logout, old password compatibility, HTTPS and named rate limits. |
| Mobile bearer | `PersonalTokenTest` verifies expiring hash-only storage, real bearer requests, isolated current-token logout, malformed tokens and separation from SPA authentication. |
| Recovery | `PasswordRecoveryTest` covers generic responses, async dispatch, authorized SMTP and URL configuration, fake mail, fragments, broker expiry, single use, invalid input and invalidation of sessions/tokens. `ConcurrentIdentityTest` exercises a competing reset. |
| Account management | `UserManagementTest` verifies unauthenticated and ordinary-user denial on each action, reserved-role precedence, safe resources, field limits, normalized duplicate emails, explicit actions, self-deactivation and stale sessions after re-enable. `ConcurrentIdentityTest` races two administrator disables and a token issue against disablement. |
| Localization and contract | `IdentityLocalizationTest` covers locale storage, the authenticated message language, translation key parity and the recipient's email language. `IdentityContractTest` validates actual v1 responses against the Phase D OpenAPI document and checks debug-safe errors. |

Run `composer test`, `composer test:database`, `composer test:contract`, `XDEBUG_MODE=coverage composer test:coverage`, `composer test:types` and `XDEBUG_MODE=coverage composer test:mutation` from the activated checkout. Use the existing CI workflow for the complete gates. HTTP fixtures use synthetic credentials and fake mail. SMTP delivery and the functional recovery screen belong to later phases.

The first Phase D candidate passed 116 Pest tests, including 42 integration and five contract tests. It measured 99.3% line coverage and 100% measurable type coverage. Its full mutation score was 75.33%. The follow-up consolidates shared validation and adds regression assertions for the surviving security boundaries. The PR records the final candidate measurements. [Phase D PR](https://github.com/alexbonavila/ExpedFlow/pull/11)

## Phase E requirement-to-test matrix

| Block | Verified behavior and suite |
|---|---|
| E01 authorization contract | `PermissionKeyTest` checks the protected key set. `AuthorizationContractTest` validates the API's full catalog and stable order against OpenAPI. |
| E02 installation and upgrades | `AuthorizationCatalogTest` and `AdministratorBootstrapTest` cover both setup orders, existing active and disabled accounts, custom roles and direct grants, preserved credentials and relations, one-time membership backfill, role name collisions, repeat seeding and rollback on refusal. `UserManagementTest` verifies the ordinary role on account creation. |
| E03 role and permission administration | `RoleManagementTest` exercises the real HTTP CRUD and grant endpoints, all three metadata locales, name collisions and protected names, disabled assignments, no credentials or pivots in the response and invalid, duplicate, wildcard, cross-guard or restricted grants. |
| E04 role assignment and effective permissions | `UserAuthorizationTest` covers atomic replacement, real CSRF, existing sessions and bearer tokens, retained model freshness, grant union and dormancy, disabled accounts, prevention of self-demotion and non-admin escalation. |
| E05 Laravel authorization boundaries | `UserManagementTest`, `DomainAuthorizationTest` and `AuthorizationContractTest` exercise 401 for guest, disabled and malformed credentials, 403 for ordinary, restricted custom and ungranted admin, 404, 409, 419 and 422, localized safe error bodies and scoped domain decisions. Phase D credential limit tests retain 429 coverage. |
| E06 transactional consistency | `ConcurrentIdentityTest` uses independent MySQL connections in separate PHP processes for competing demotions, disable/demotion, membership/deletion, grant replacements, password replacement after loss of authority or credential generation, initializer/seed contention and permission cache commit/rollback. |
| E07 deletion and restoration | `DeletionAuthorizationTest` exercises real persisted cases, tasks, comments and catalog references. It verifies authorized soft deletion, scoped trash, cascade eligibility and admin-plus-permission force-deletion decisions. The Phase D account surface still rejects user deletion. |
| E08 API contract and regression | `AuthorizationContractTest` validates actual `/api/v1` authorization request and response contracts, including protected JSON success and errors. The identity OpenAPI contract and full Phase B quality gates continue to run without exceptions. |

Use `APP_ENV=testing php tests/Support/check-database.php` before database test suites. The contention tests coordinate through a MySQL query barrier using synthetic users, data and credentials. No test calls production storage or SMTP. Domain tests exercise Gates and Policies directly. Service writes, cascade effects, list scoping and future endpoint contracts are prerequisites for their respective later phases.

## Phase E.1 local verification

Activate the existing Codex runtime in each Bash shell. Use focused local checks and hand full mutation verification to the isolated hosted matrix. A targeted diagnosis can run the following commands from the activated checkout.

```bash
source /workspace/.expedflow-runtime/activate.sh
APP_ENV=testing php tests/Support/check-database.php
XDEBUG_MODE=coverage php -d memory_limit=512M vendor/bin/pest --mutate --path=app/Support/EffectivePermissions.php --no-cache
```

Use `python3 -B -m unittest discover -s scripts/testing -p test_mutation.py` to verify the planner, global score and gate failures. The tests cover invalid and incomplete inventories, missing shards, duplicates, stale configuration, failed processes, infrastructure failures, timeouts and below-threshold scores. Hosted jobs use the unchanged 90% line, 100% measurable type and 80% mutation minima and the complete existing test suites. The sequential Composer command remains available for an explicitly needed reference investigation.

Initiate the candidate's hosted checks and record exact URLs and SHA. Let long-running jobs finish independently and use specific failure evidence or owner follow-up to resume investigation. See CI.md for artifact interpretation and the workflow filename transition.

## Phase F requirement-to-test matrix

| Requirements | Evidence |
|---|---|
| R02 and F04 authorization | `ExpedientApiTest` exercises global scope, separate permissions, disabled and revoked credentials, JSON denial before ID disclosure and genuine SPA CSRF. `ExpedientContentionTest` checks an admitted writer after concurrent grant, token and database-session revocation. |
| R03 and F02 clients | `ClientCatalogTest` covers Unicode boundaries, length, duplicates, self-update, both reference roles and deleted case references. `ExpedientContentionTest` covers concurrent duplicate inserts and the MySQL reference backstop. |
| R04 and F03 annual numbering | `ExpedientApiTest` covers leading zeros, two types, Madrid midnight rollover, immutable fields, reclassification, invalid references and rollback. `ExpedientContentionTest` blocks a second connection on the same first-year and final-slot annual row. `ExpedientLifecycleTest` verifies non-reuse after physical deletion. |
| R06, R07 and F05 order | `ClientCatalogTest` covers localized status fallback and type ordering. `ExpedientApiTest` verifies destination append, no-op edits, version changes and preserved hidden flags and task state. `ExpedientContentionTest` verifies concurrent moves allocate distinct positions. H owns personal placement and reorder interactions. |
| R09 and F06 lifecycle | `ExpedientLifecycleTest` covers active/trash scope, repeated cycles, independent deletions, restored positions and collisions, rollback, retained relations, and admin-only FK cascades with preserved history. `ExpedientContentionTest` covers an independent child writer blocked on the parent. G must apply that contract to real task routes. |
| R10 and R11 boundaries | `ExpedientLifecycleTest` verifies retained storage contents, incident snapshots and audit rows in the case lifecycle. I owns real provisioning and failure recovery. J owns audit event recording and cleanup. |
| R12 and F08 contracts and CI | `PhaseFContractTest` validates live responses against the new OpenAPI fixture and retains earlier suites. `ExpedientApiTest` checks complete lists and bounded relation queries. Existing mutation planner and aggregate tests verify both worker options and the required gate. |

Use the guarded MySQL connection before destructive tests. Full mutation verification runs in the hosted matrix. Phase F tests use synthetic data and independent database processes with query barriers for contention.

## Phase G requirement-to-test matrix

| Requirement | Evidence |
| --- | --- |
| R02 identity and permissions | `TaskApiTest`, `TaskAssigneeTest`, `TaskCommentTest`, `TaskChecklistTest`, `TaskLifecycleTest` and `PhaseGContractTest` cover policy scope, custom grants, disabled and roleless users, bearer authentication, real session CSRF and localized errors. `TaskContentionTest` covers grant and credential revocation after request admission. |
| R05, R06 and R07 tasks and shared order | `TaskApiTest` covers allowlisted writes, explicit status, strict civil values, date/time combination, shared ordering, rank overflow and rollback. `TaskContentionTest` covers competing moves and appends. |
| R05 assignments, comments and checklist | The corresponding feature suites cover assignment replacement, disabled users and rollback, peer comment edits with original author, comment trash, and checklist ordering and completion. `TaskContentionTest` checks replacement, disable and stale checklist membership in separate MySQL processes. |
| R08 scope and calendar | `TaskCalendarTest` covers private calendar scope, shared assignments, date filters, missing dates, null time versus midnight, personal board preferences, and deleted cases or tasks. |
| R09 lifecycle | `TaskLifecycleTest` covers repeated case cycles with actual task API writes, position reuse, preserved descendants, independent trash and FK cascades. `TaskContentionTest` covers active parent checks for all task-writing paths and a task creation that commits before deletion. |
| R11 audit handoff and R12 API | `TaskCommentTest` verifies actor and author behavior. `PhaseGContractTest` validates live request and response shapes against the OpenAPI fixture. Centralized event recording and retention stay with J. |

Guard the dedicated MySQL schema before Pest. The full local coverage run executes Feature, Integration, Contract, Architecture and Unit in one pass. The browser, accessibility, Samba, mutation, security and load gates run in hosted CI.

The Phase G local regression passed 239 tests and 2,874 assertions with 98.7% line coverage and 100% measurable type coverage. The guarded run used `APP_ENV=testing php tests/Support/check-database.php`, then `XDEBUG_MODE=coverage php -d memory_limit=512M vendor/bin/pest --coverage --min=90 --coverage-clover=coverage/clover.xml --log-junit=test-results/phpunit.xml`. Composer strict validation, Pint, Larastan level 8, `composer test:types` and all 14 mutation-tooling unit tests passed. The full run included the database and contract suites.

## Phase H requirement-to-test matrix

| Requirements | Evidence |
|---|---|
| R02 authorization and R12 HTTP | `PhaseHContractTest` validates authenticated JSON against `tests/Fixtures/phase-h-openapi.json` and checks bearer/session parity, CSRF, localized errors, roleless and disabled access. `CatalogContentionTest` checks current grants and credentials after waiting on authorization. |
| R04 type and atomic copy | `ExpedientTypeCatalogTest`, `TaskTemplateCatalogTest` and `TemplateCopyTest` check immutable types, exact nested scopes, independent ordered copies, explicit status, grant checks and rollback of the annual counter. |
| R06 statuses | `StatusCatalogTest` covers initial localization, rename/reseed, complete ordering, active and trashed references, rollback, and reserved-admin enforcement. |
| R07 and R09 private board and lifecycle | `PersonalKanbanTest` covers private ranks and visibility, same and cross-column movement, bounds, generic F transitions, and cleanup after physical deletion. |
| R08 tags | `TagCatalogTest` covers collation uniqueness, exact RGB input, private response fields, replacement rollback and link lifecycle. |
| R04, R06–R09 MySQL contention | `CatalogContentionTest` uses independent processes, query barriers and guarded MySQL to exercise type uniqueness and references, status references and removal, template snapshots, tag replacement and same/cross-column ordering. |

Run the dedicated database guard before destructive tests. Phase H uses the existing isolated test schema and the accepted lifecycle and task schema. G09 adds joint G/H regression for actual task endpoints. Post-commit storage recovery remains a Phase I integration check.

The final isolated MySQL pass ran `APP_ENV=testing php tests/Support/check-database.php` followed by `XDEBUG_MODE=coverage php -d memory_limit=512M vendor/bin/pest --coverage --min=90 --coverage-clover=coverage/clover.xml --log-junit=test-results/phpunit.xml --compact`. All 237 tests passed with 2,720 assertions and 99.0% line coverage. `composer test:types` passed at 100% measurable declaration coverage. `composer validate --strict --no-check-publish`, `vendor/bin/pint --dirty --format agent`, `composer analyse --no-ansi` at Larastan level 8, and the 14 tests from `python3 -B -m unittest discover -s scripts/testing -p test_mutation.py` also passed.

## G09 combined G/H regression

`TaskCatalogIntegrationTest` creates cases from H templates and uses G endpoints to mutate and expose their independent copies. It covers rollback after an earlier copy was written, shared status order and deletion restrictions, calendar visibility, case deletion and restoration, and physical cleanup while retaining blueprints. It exercises the task, catalog and default error groups through the HTTP API in Spanish, Catalan and English. `CatalogContentionTest` verifies both commit orders for a G task move and H status deletion, plus a concurrent G task move and H global case move through independent guarded MySQL processes. The standalone F, G and H contract suites remain in the full regression.


## G11 mutation regression and performance fixture

Run the regression suite with `python3 -B -m unittest discover -s scripts/testing -p test_mutation.py`. It covers historical trust and fingerprints, source and test changes, changed dependencies and shared inputs, deterministic scheduling, mixed outcomes, evidence reconciliation and the required CI gate. The bounded native fixture runs with `python3 -B scripts/testing/benchmark_mutation.py`. It uses Pest and the existing mutation adapter in a disposable project and compares every reconciled outcome with an independent full run of the small fixture.

The local fixture measured the following results. Preparation includes coverage and inventory plus the initial suite for any mutation worker. Runner seconds include fixture preparation and workers. These results measure the policy on a small fixture and provide no hosted PR latency claim.

| Scenario | Inventory | Fresh | Reused | Hit rate | Workers | Prep (s) | Execution (s) | Wall (s) | Runner (s) | Fixture full wall (s) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| No relevant changes | 12 | 0 | 12 | 100% | 0 | 0.242 | 0 | 0.250 | 0.242 | 2.203 |
| Documentation only | 12 | 0 | 12 | 100% | 0 | 0.254 | 0 | 0.261 | 0.254 | 2.355 |
| Small application edit | 12 | 4 | 8 | 66.7% | 1 | 0.446 | 0.523 | 0.978 | 0.970 | 2.274 |
| Changed test | 12 | 4 | 8 | 66.7% | 1 | 0.456 | 0.512 | 0.976 | 0.968 | 2.297 |
| Isolated new module | 16 | 4 | 12 | 75% | 1 | 0.468 | 0.536 | 1.012 | 1.004 | 3.106 |
| Shared fixture dependency | 12 | 4 | 8 | 66.7% | 1 | 0.463 | 0.580 | 1.055 | 1.043 | 2.539 |

On the dependency edit, a previously detected mutant in the unchanged caller survived. Both paths reported the same classifications and the incremental gate failed on the fresh slice, as intended.

## Phase I storage verification

Normal Pest runs exercise the adapter with a test-only mount verifier and a disposable local filesystem, plus the guarded MySQL API. The dedicated storage-integration job runs the same adapter and the Linux verifier against the isolated CIFS fixture. The fixture tests recursive copy, empty directories, Unicode upload integrity, filename conflicts, the reader ACL, a client read-only mount, wrong-source rejection and mount loss during a transfer. It remounts the same share to check that the previous upload survived and that a partial file cannot be replaced. The underlying directory is owned by root and unwritable by the invoking account. The local subset is bash scripts/testing/storage.sh --check-unmounted.

### Phase I standalone CIFS authorization correction

The original standalone writer fixture enabled production-mode HTTP security before migrating and seeding. Its forced migration succeeded. Laravel then declined the noninteractive authorization seed command because it detected the production environment. Laravel's testing seed helper did not assert the command's exit status. The ordinary web role was absent from MySQL when Spatie queried it.

The CIFS fixture now checks the dedicated test connection and verifies migration and authorization seeding while the application is in its testing environment. It then enables production HTTP security. The guarded standalone regression checks the persisted web role, effective case creation and upload grants, and access after booting a new application against the same database. The real mount suite keeps the ordinary actor and also checks the annual sequence and retained files across retry, soft deletion and restoration.

The guarded authorization reproduction runs without a mount. Local missing-mount smoke passed. The full writer, wrong-source, reader, read-only mount, missing-mount, live loss and reconnect stages require the hosted Linux job because the local environment lacks sudo and cifs-utils.

The I11 guarded regression passed 265 tests and 2,607 assertions with 97.74% line coverage (3,116 of 3,188) and 100% measurable type coverage. Strict Composer validation, Pint, Larastan level 8, all 16 mutation tooling tests, actionlint, ShellCheck and the missing-mount smoke passed.
