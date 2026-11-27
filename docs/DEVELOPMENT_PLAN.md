# Development and acceptance plan

## Order and dependencies

Phase 0 establishes requirements and documentation. A → B → C must run in that order. Accept the D–K backend before L. Build the L–P functional frontend on validated contracts. Q/R consolidate and distribute the application. Define S later. Start later phases only when authorized and keep changes within the requested scope. Parallel work requires agreement and must preserve dependencies and contracts.

| Phase | Scope and deliverables | Exit criteria |
|---|---|---|
| 0 | Documentation, requirements, architecture, standards, tests and plan | Consistent documents, technical proposals distinguished from requirements, no functional code |
| A | Laravel foundation, GitHub, MySQL, configuration and environments | Local startup and database connection, repository and documentation, secret-free `.env.example`, lockfiles and base commit |
| B | Complete testing, CI and quality controls | Verified harnesses and gates, adversarial tests, main branch protection and actual evidence, with no early business functionality |
| C | Complete database | Final dictionary, migrations, models, factories, seeders, foreign keys, indexes, constraints and MySQL tests, with migration and rollback verified |
| D | Authentication and users | Sanctum, sessions and tokens, login, logout and reset, administrator bootstrap, closed registration, user management, locale and tests |
| E | Roles and permissions | Spatie, initial and configurable roles, Policies, authorization matrix and escalation, soft deletion and hard deletion tests |
| F | Clients, cases and numbering | CRUD, unique clients, two client relationships, concurrent annual codes, limit of 999, initial statuses, case soft deletion and restoration, and cascade coordination with G |
| G | Tasks, assignments, comments and checklists | CRUD, multiple assignees, one optional date, shared order, editing other users' comments, and correct cascade and restoration |
| H | Statuses, templates, tags and preferences | Shared catalog, referential integrity, independent template copies, colors and unique names, and multi-user Kanban behavior |
| I | Samba and incidents | Template copies, API uploads, failure or missing-mount recovery and incident table, with real SMB tests and no listing or deletion |
| J | Auditing | Business changes and actors, relationship changes, annual retention, scheduler and tests, with auditing of earlier flows verified |
| K | API consolidation | Complete endpoints and policies, OpenAPI, contracts and JSON errors, unpaginated filters, backend integration, security and regression tests |
| L | Frontend infrastructure | Compatible Vue and AdminLTE, router, localization, API client, layouts, login, build and tests |
| M | Administration | Users, roles, permissions, statuses, templates and catalog as authorized, with E2E tests |
| N | Clients and cases | Creation and editing, types, tags, tables, trash, incidents and basic export |
| O | Kanban | General board with personal order and visibility, shared task board, drag and drop with an accessible alternative, and two-user E2E tests |
| P | Calendar, tables and CSV | All-day and timed assigned tasks, local filtering, sorting and pagination, CSV and tests |
| Q | Comprehensive validation | Full E2E, browser, accessibility, performance, security, Samba failure and recovery, quality and regression tests |
| R | Installation and stable release | Apache/PHP/MySQL, SMTP, Samba, cron and traditional setup instructions, with installation and upgrades verified |
| S | Future Android app | Define its own requirements, use the API contract and Sanctum, and wait for scope approval before development |

Phases F–J may need models or catalogs created in C even if their administrative endpoints come later. A cross-cutting rule is complete only after verification in the phase that incorporates all of its dependencies. Consolidate auditing in J while retaining coverage of earlier changes.

## Phase A instructions

1. Check the environment and select supported, compatible Laravel/PHP/MySQL/Node versions. Record the actual versions. Create a clean Laravel project at the repository root without a functional frontend or unnecessary domain packages.
2. Add the twelve Phase 0 files. Update startup documentation with actual commands. Keep standard Laravel files and conventions.
3. Set up the MySQL development connection and a separate test database. Put necessary non-secret variables in `.env.example`. Check `.gitignore` and lockfiles. Prepare language and time zone settings and defer functional models.
4. Verify startup, baseline framework migrations against a disposable database and the existing baseline tests. Record commands, versions, results and commit. Defer installation of the ExpedFlow schema.
5. Confirm the GitHub repository and a clean working tree. Report pushes or CI only after verifying them. Close A before starting B.

## Phase B instructions

### B01 — Inventory and tools
Read TESTING.md/CI.md. Select compatible versions of Pest/PHPUnit and its plugins, Pint, Larastan, frontend tools, Playwright and supporting tools. Install only necessary dependencies and record lockfiles. Prepare the test environment, scripts and documentation. Choose measurable thresholds and justify them.

### B02 — Backend and database
Configure discovery for unit, feature, integration, architecture, contract and regression suites, along with line and type coverage and mutation testing. Use isolated real MySQL. Provide runnable tests for baseline migrations, rollback, existing factories and seed data, transactions and concurrency. Configure Laravel fakes and storage, mail and console tests. Defer business models.

### B03 — Frontend and browsers
Provide a minimal Vue harness, ESLint, Prettier, TypeScript, Vitest and Vue Test Utils. Build with Vite and use a real temporary server. Run Playwright in Chromium, Firefox and WebKit, use axe-core and test data, and capture screenshots and traces on failure. Keep the harness limited to testing. Integrate JSON contracts and minimal verifiable localization.

### B04 — Security, performance and storage
Configure Composer/npm audits, Gitleaks, CodeQL, Dependabot, ZAP, k6 and a test Samba share. Run a reproducible smoke test. Keep real company systems out of testing. Resolve SMB runner compatibility and permissions or document the blocker and keep the phase open.

### B05 — Workflows
Automate jobs for PRs, main pushes, nightly runs and releases with least privilege and artifacts. Share local and CI scripts, readiness checks and timeouts. Configure the aggregate gate and branch protection. Test manual dispatch for the scheduled checks. Production CD is out of scope.

### B06 — Control tests
Run the deliberate failure matrix from CI.md on temporary branches. Verify detection, the gate and actual integration blocking, then revert each change. Verify a final clean and green main branch. Record actual run URLs and IDs, results, versions and effective protection settings.

### B07 — Acceptance
Deliver the tool inventory, commands, suites and workflows, actual local and CI results, metrics, gate tests, maintenance documentation, and functional categories deferred to C–Q. Authorize C only after B passes every mandatory control. Record permission, runner and service blockers accurately.

## Closeout format for every phase

- Phase, commit and branch, with the requested and delivered scope.
- Met requirements and acceptance criteria with evidence.
- Changed files, technical decisions and reasons.
- Tests and commands run, with separate local and CI results.
- Failures, limitations and legitimately deferred work. Report execution only with evidence.
- Working tree and integration status, plus a recommendation for the next phase. Do not start it automatically.

## Status of this document

The Phase 0 package documents the plan and records A–S as pending at that baseline. The package did not create a repository, Laravel project, dependencies or CI. Finalize technical implementation decisions in their assigned phases and preserve confirmed requirements.

## Phase A and B status

Phase A is closed on the owner's supplied baseline evidence for commit `22528be`. The cloud reran the two inherited tests and Pint and confirmed the base Laravel page. The original cloud Vite configuration depended on a font CDN that the proxy blocked. The Phase B build now bundles the same font from the npm lockfile and ran locally.

Phase B was integrated through PR #1 at `f33b90f`. The default-branch push and actual manual extended workflow passed. The English documentation and acceptance audit were integrated through PR #5 at `5436acb`. Actual protected-PR merge blocking was verified in PR #6; its deliberate fault was reverted. The Node declaration update policy was integrated through PR #7. The [CI closeout record](CI.md) identifies final run verification and outstanding operational event checks; [SECURITY.md](SECURITY.md) records dependency and alert-review follow-up. The owner accepted Phase B and authorized Phase C from protected main commit `430c9a4`. The scheduled workflow passed. Published-release event verification and independent CodeQL alert inventory review remain operational follow-up.

## Phase C status

The candidate on `feat/database-schema-c` delivers the functional schema, Eloquent relations, localization persistence, initial status seed and synthetic MySQL verification. See [DATABASE.md](DATABASE.md) for the dictionary and invariants and [TESTING.md](TESTING.md) for local results. The owner accepted Phase C after integration through PR #10 at `942e5cd` and authorized Phase D. The [CI record](CI.md) lists the verified core, extended and post-merge runs.

## Phase D status

Phase D was accepted and integrated through [PR #11](https://github.com/alexbonavila/ExpedFlow/pull/11) at `6d8eef9c00f683f2e48e17ff80a18e6019702c11`. The [post-merge workflow](https://github.com/alexbonavila/ExpedFlow/actions/runs/45997572529) passed. The owner accepted Phase D and authorized Phase E from that protected baseline.

## Phase E authorization

The implementation branch is `feat/authorization-roles-e`. Phase E delivers the fixed permission catalog, protected system roles, authorized role and membership management, and reusable domain policies. The [authorization matrix](SECURITY.md#phase-e-authorization-matrix) records the scope. The owner accepted E and E.1 after protected integration through PR #12 at `b16f3329813e884c7b2c4ec1a222cfc9c05d8620`.

## Phase E.1 CI scalability

Phase E.1 continues on `feat/authorization-roles-e` and PR #12. It introduces isolated distributed mutation testing, global evidence reconciliation, locked dependency-download caches and same-run asset reuse. ExpedFlow CI retains its required `quality-gate` check. See CI.md for the baseline, workflow filename transition and verification procedure. The accepted four- and eight-runner checks reproduced the sequential results. Post-merge CI passed, and the owner authorized Phase F. Eight workers are the Phase F default.
