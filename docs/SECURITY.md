# Security

## Threat model

Treat internal applications as untrusted. Authenticated users may attempt to escalate privileges, access other users' preferences, permanently delete data, alter identifiers or submit malicious paths or files. Consider session or token theft, XSS, CSRF, SQL injection, brute force, vulnerable dependencies, Samba mount errors and data leaks in logs or CI.

## Identity and access

Use Sanctum, Laravel's native hashing, session regeneration at login and invalidation at logout, appropriate Secure/HttpOnly/SameSite cookies, CSRF for the SPA, and revocable Android tokens with a defined lifetime. Use HTTPS even on the LAN. Rate-limit login, reset, API and upload operations as needed. Use SMTP password recovery with expiration and messages that prevent account enumeration. Omit or disable public registration. Never store passwords in plaintext.

The proposed `ADMIN_NAME`, `ADMIN_EMAIL` and `ADMIN_PASSWORD` variables initialize the first administrator through an idempotent Artisan command that hashes passwords, validates input and keeps the secret private. Preserve existing accounts at startup and remove bootstrap credentials after use. Keep actual values out of `.env.example`. Use Spatie roles and permissions and Policies per resource. Always check authorization on the server and prevent non-administrators from assigning privileges. Hard deletion requires both the administrator role and the corresponding permission. Protect that operation from accidental delegation. Restrict access to children of deleted cases and to other users' preferences. Revoke sessions and tokens when a user is disabled, according to the policy defined in D.

## Input and output

Use Form Requests, field and sorting allowlists, Eloquent or parameterized queries, mass assignment controls, request size limits, types and encoding. Escape user text and prevent arbitrary comment HTML from rendering. Prevent spreadsheet formula interpretation in CSV, including cells starting with formula characters, and apply correct encoding and escaping. Keep stack traces, SQL and private paths out of API JSON. Never serialize passwords, tokens or configuration data. Apply CSP and appropriate secure HTTP headers. Keep CORS minimal and prefer same-origin access.

## MySQL and auditing

Grant the application's MySQL user the minimum privileges it needs and keep test credentials separate. Enforce database constraints alongside validation. Audit the actor and differences while excluding passwords, hashes, tokens, cookies, credentials and files. Retain audit history for one year through scheduled cleanup. Keep request logs separate from audit history. Show incidents with a safe message and useful reference. Restrict access to technical logs. Avoid accumulating unnecessary personal data. The company handles backups. Document that restoration requires consistent configuration, MySQL and Samba state.

## Samba and files

- Mount outside the document root and use a least-privilege service account. Prefer SMB/ACL permissions that prevent deletion of existing content where feasible. The application provides no document deletion or listing actions.
- Verify that the mount is available and the root is authorized before copying or uploading. Prevent writes to the local directory when Samba goes down. Reject arbitrary client paths. Validate or sanitize case and file names, reserved characters, length, `..`, separators, symlinks and path resolution. Preserve the code and space in the destination folder and handle incompatibility explicitly.
- Use configurable limits, allowed types as needed and streaming. Never execute files, silently overwrite them or follow links outside the root. Handle collisions and partial failures without deleting existing documents. Keep binaries out of the database and prevent Apache from serving them. Record an incident without sensitive details when a failure occurs.
- Keep SMB credentials, absolute paths and folder structure out of the API and UI. Provide only authorized upload endpoints. Hard deletion of a case must never invoke Samba operations.

## Production and supply chain

Use `public/` as the Apache document root. Disable debug and directory listing, minimize file permissions, use supported dependencies, keep configuration cacheable, rotate logs and run the scheduler through cron. Keep secrets out of Git and artifacts. Run Composer/npm audits, Gitleaks, CodeQL and ZAP in CI according to CI.md. Analyze and resolve relevant alerts. Scan company systems only with authorization. Use least privilege, synthetic data and isolated runners in CI.

## Tests

Include negative cases for 401/403, CSRF, rate limiting, role escalation, IDOR, soft and hard deletion, injection, XSS, CSV, path traversal, collisions, missing mounts, revoked tokens and sensitive data exposure. See TESTING.md. Continue security checks from B through the final security validation in Q.

## Phase B checks and finding management

Run `composer audit --locked --no-interaction` and `npm audit --audit-level=high` against the checked-in lockfiles. Composer reports package advisories as failures. npm reports all severities and fails for high and critical findings. Use `bash scripts/testing/secrets.sh` for redacted, full-history Gitleaks scanning. Its single fingerprint allowlist entry covers a reviewed documentation row in the original Phase B inventory. The default rules and all other findings remain active. Dependabot checks Composer, npm and GitHub Actions weekly.

Build the frontend and use the guarded testing database before running `bash scripts/testing/zap.sh`. The pinned ZAP image runs the Automation Framework's passive baseline against the loopback Laravel process. The automation plan scopes scanning to this server, stores HTML and JSON under `test-results/zap/`, and exits nonzero on warning-or-higher findings according to its risk thresholds. Review findings by rule, target route and risk. Remediate and rerun when possible. Record any owner-authorized disposition by rule, route and affected parameter.

### Passive scan dispositions

| Finding | Affected response in the baseline | Disposition |
| --- | --- | --- |
| 10038 CSP missing | `/` and the `/sitemap.xml` error response | Laravel emits a same-origin CSP with a fresh per-request nonce. The nonce covers Vite-generated tags, font styles and the welcome fallback stylesheet. HTML and JSON error tests exercise the policy. |
| 10020 Anti-clickjacking header missing | `/` | CSP denies framing and `X-Frame-Options: DENY` covers clients that use the legacy header. |
| 10037 X-Powered-By disclosure | `/` and `/sitemap.xml` | The loopback PHP listener disables `expose_php`. The Apache document-root rules clear the disclosure header in both header tables. Deploy with `expose_php=Off` in the active PHP SAPI. |
| 10021 MIME sniffing | `/`, compiled stylesheet and preloaded font files | Laravel sends `X-Content-Type-Options: nosniff`. Apache sets it on static assets and the loopback harness sends it with explicit asset MIME types. |
| 10010 Cookie missing HttpOnly | `/`, parameter `XSRF-TOKEN` | The single ZAP filter marks this exact rule, cookie and loopback root URL as a false positive. Laravel intentionally exposes the encrypted token to same-origin clients such as Axios for the `X-XSRF-TOKEN` request header. The session cookie stays HttpOnly. A regression verifies that Laravel rejects a POST without a token and accepts the issued encrypted token. [Official Laravel 13 CSRF documentation](https://laravel.com/docs/13.x/csrf#csrf-x-xsrf-token) |
| 10112 Session Management Response Identified | `/`, parameter `expedflow-session` | Informational evidence of a session cookie. The gate remains at Low. |

The secure-cookie default is enabled and the HTTPS regression checks Secure and SameSite=Lax on both cookies plus HttpOnly on the session cookie. The isolated HTTP harness explicitly sets `SESSION_SECURE_COOKIE=false`. Keep HTTPS and Secure enabled for production. Enable Apache `mod_headers` and allow FileInfo overrides for the public document root, or apply equivalent directives in the virtual host. With compiled assets, the Vue frontend uses the enforced policy. Laravel local development with a Vite hot file emits the policy in report-only mode for HMR. Testing and production enforce it even if a hot file exists.

A fresh unfiltered scan after the header changes reported only the expected root XSRF cookie and the informational session finding. With the scoped disposition, the scan passed. The wrapper also checks each fresh JSON report entry for Low or higher risk with nonzero confidence. This preserves the gate when ZAP groups a false-positive and a confirmed alert under the same rule. A deliberate `SESSION_HTTP_ONLY=false` run confirmed that the real session-cookie finding still fails the gate. The Composer audit was verified by the hosted workflow because the local proxy denied Packagist advisories. The local npm audit reported moderate development-chain findings and passed its High threshold.

### Post-merge dependency review

The hosted security job passed at `f33b90f`, and the manual extended run passed the dynamic security gate. CodeQL execution also passed. Review the alert inventory through an authorized GitHub session for an independent severity assessment. [Main run](https://github.com/alexbonavila/ExpedFlow/actions/runs/45832019557) · [Manual extended run](https://github.com/alexbonavila/ExpedFlow/actions/runs/45832795169)

| Proposal | Compatibility and evidence |
| --- | --- |
| [PR #2](https://github.com/alexbonavila/ExpedFlow/pull/2), actions/checkout 7.0.1 | The pinned action uses its own Node 24 runtime. Ubuntu 24.04 hosted checks passed. The workflow uses `pull_request`, so the new fork safeguards for privileged events do not affect its current checkout path. [PR run](https://github.com/alexbonavila/ExpedFlow/actions/runs/45832174531) |
| [PR #3](https://github.com/alexbonavila/ExpedFlow/pull/3), @types/node 26.6.6 | Frozen install, declaration check and Vitest passed under the pinned Node 24.22.3. Node 26 declarations can permit APIs absent at runtime on Node 24. Keep the declaration major aligned with the current runtime. [PR run](https://github.com/alexbonavila/ExpedFlow/actions/runs/45832205215) |
| [PR #4](https://github.com/alexbonavila/ExpedFlow/pull/4), jsdom 31.0.1 | Its Node 24 range starts at 24.15.0. The pinned 24.22.3 satisfies it and Vitest 5.1.2 accepts the jsdom peer. Frozen install, Vitest, type check and Vite build passed in isolation. [PR run](https://github.com/alexbonavila/ExpedFlow/actions/runs/45832265102) |

The npm Dependabot updater failed in [run 45832034121](https://github.com/alexbonavila/ExpedFlow/actions/runs/45832034121) and again in [run 45850555534](https://github.com/alexbonavila/ExpedFlow/actions/runs/45850555534). Composer and GitHub Actions updates succeeded in the same update cycle as the later failure. Both public npm runs report a generic updater error. GitHub requires repository write access for the [first log](https://github.com/alexbonavila/ExpedFlow/network/updates/1732729864) and the [latest log](https://github.com/alexbonavila/ExpedFlow/network/updates/1732917379). The tested proposals passed under the current runtime. The accessible hosted job logs for both failed runs show npm `ETARGET`: `@typescript-eslint/eslint-plugin@8.74.0` could not be resolved before the configured version-age cutoff while updating the TypeScript peer dependency. This identifies the immediate updater failure; the upstream resolution behavior still requires follow-up if it recurs. Keep the existing dependency security policy and tested lockfile; retry through ordinary Dependabot maintenance.

The separately published Dependabot change excludes only routine major version updates for `@types/node`. Local YAML validation confirmed the targeted rule, the weekly schedules and the other ecosystem settings. The lockfile and manifest already use major 24, matching `.node-version`. Minor and patch updates within that major remain eligible, and the major-version filter leaves security updates eligible. Review the runtime pin and the declarations together when changing the Node major. The change passed [PR #7 CI](https://github.com/alexbonavila/ExpedFlow/actions/runs/45952196316) and was merged at `5f06ab8`. The three ordinary version-update PRs (#2, #3, #4) were closed without merging during the requested closeout, preserving the tested baseline. Keep compatible future updates under normal maintenance, and review the new updater results independently. [Dependabot configuration change](https://github.com/alexbonavila/ExpedFlow/commit/91926882d0ecf490ebbfe659da11f0bb16cf356b)

## Phase D identity controls

Use secure host-only session cookies, HttpOnly, SameSite=Lax and the existing 120-minute idle lifetime. The SPA reads only the XSRF cookie and uses native Laravel CSRF protection. v1 identity routes require HTTPS except under the isolated testing environment. Keep production `APP_URL` on the intended HTTPS origin and keep the configured session connection on the same MySQL database as the account and broker tables. Leave persistent remember-me disabled. Mobile clients keep their tokens in platform-protected storage. Enable the existing database cache in production so named request limits apply across application processes.

Credential verification uses Laravel hashing with a time floor for invalid credentials. New passwords use the configured hasher and bcrypt's 72-byte cap. The database enforces email uniqueness. Claim checks and admin authorization are server-side. An auth generation guards sessions written late during concurrent revocation. A password reset, administrative password replacement or disablement increments the generation, clears the remember credential and deletes sessions, reset tokens and personal access tokens. Re-enabling preserves the generation. Administrative disables hold the reserved `web` role row while deciding whether the last active admin is protected. The explicit account lock serializes issuance, password change and revocation.

### Initialize the first administrator

Populate `ADMIN_NAME`, `ADMIN_EMAIL` and `ADMIN_PASSWORD` privately in `.env` or the deployment's Laravel configuration source. Use a unique password and keep the environment file restricted. Run the command from the application installation after migrations:

```sh
php artisan expedflow:admin:init --no-interaction
```

Remove the three bootstrap values immediately after success and regenerate cached configuration if used. A same-email rerun preserves the account and password. A conflicting email or ambiguous role state requires owner review of the existing records. Keep passwords out of shell arguments, committed files, logs and email. Startup, migrations and general seeding leave accounts and roles untouched.

### SMTP recovery

Configure `APP_URL` with the trusted HTTPS installation origin. Set `MAIL_MAILER=smtp` and configure `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`, `MAIL_PASSWORD` and `MAIL_FROM_ADDRESS` privately as appropriate for the provider. Use TLS. Run the existing database queue with a supervised `php artisan queue:work database` process. All valid recovery requests enqueue identical work regardless of eligibility. The encrypted job stores the normalized email. The broker creates the hashed reset token in MySQL and the plaintext appears only in the recipient's mail. Recovery requires an SMTP transport. Log and array transports cannot receive recovery notices or cause token issuance. Failed SMTP delivery rolls back the token and records a credential-free warning. The reset link uses a URL fragment to keep its values out of HTTP access logs. Verify real SMTP and the reset screen in the later installation and frontend phases.

## Phase E authorization matrix

Spatie's `web` guard applies to both Sanctum transports. An authenticated active account can use its own identity, locale and applicable logout endpoints without a role. Other access requires an effective permission from the fixed catalog. The initial `admin` role receives the entire catalog. The initial `user` role receives every delegable key. Administrators may tailor delegable grants on either role or on custom roles. Restricted keys always require an active member of `admin` and an explicit grant. Ordinary, roleless and custom-role accounts never gain restricted access from direct grants or token abilities.

| Resource and operation | Permission keys | Assignment and scope |
|---|---|---|
| Accounts, passwords, activation and deactivation | `users.manage` | Restricted. No account deletion. |
| Permission catalog and role administration | `roles.manage` | Restricted. Both system roles are protected. |
| Inspect account authorization and replace role memberships | `roles.assign` | Restricted. An administrator cannot demote themself or leave zero active administrators. |
| Shared statuses | `statuses.view`, `statuses.manage` | View is delegable. Creation, editing, reordering and removal are restricted. MySQL references include deleted records. |
| Clients and case types | `clients.view`, `clients.manage`; `expedient-types.view`, `expedient-types.manage` | Delegable. Catalog management includes removal subject to database references. |
| Cases | `expedients.view`, `expedients.create`, `expedients.update`, `expedients.delete`, `expedients.restore`, `expedients.force-delete` | Authorized access covers all cases without ownership or assignment filtering. Soft deletion and restoration are delegable. Permanent deletion is restricted. |
| Tasks | `tasks.view`, `tasks.create`, `tasks.update`, `tasks.assign`, `tasks.reorder`, `tasks.delete`, `tasks.restore`, `tasks.force-delete` | Check the declared case, its active state and case access. Only permanent deletion is restricted. |
| Task comments | `task-comments.create`, `task-comments.update`, `task-comments.delete`, `task-comments.restore`, `task-comments.force-delete` | Check the declared task and case. Active comments are read with task access. Authorized changes can affect another user's comment. Only permanent deletion is restricted. |
| Task checklists | `task-checklists.manage` | Delegable. Check the declared task and case. Active items are read with task access. No checklist trash. |
| Templates and template checklists | `task-templates.view`, `task-templates.manage` | Delegable. Check the template's declared type and the item's declared template. No template or template checklist trash. |
| Tags and case-tag associations | `tags.view`, `tags.manage`, `tags.associate` | Delegable. Associating tags also requires access to the active case. Catalog deletion uses the declared relational behavior. |
| Personal Kanban preferences | `kanban.manage` | Delegable. Only the actor's preferences for accessible active cases. |
| Calendar | `calendar.view` | Delegable. Only active dated tasks assigned to the actor, with access to their cases and tasks. |
| Case uploads and incidents | `case-files.upload`, `incidents.view` | Delegable. Upload requires access to the active case. Incidents use a future safe projection. Samba browsing, listing, downloads and removal are unavailable to all roles. |
| Audit log visibility | Reserved for J | Audit visibility and incident retention remain undecided. Never expose raw logs or operational secrets. |

Phase E implements the authorization boundaries. F through J must wire the applicable decisions into real routes and transactions, with no per-case ACL, hierarchical roles or tenancy.

### Transactional authorization and cache

Every administrative write acquires the reserved `admin` web role row first, then locks relevant user rows by ascending ID and locks any additional target roles by ID. The first-administrator command and catalog installer use the same reserved row. Recheck actor activity, credential generation, administrator membership and the operation's current permission inside the write transaction. The disable and membership paths share the last-active-administrator check. Conflicts roll back without modifying credentials or assignments. Proposed business writes in later phases must recheck their permissions and invariants inside the relevant mutation transaction.

The authoritative decision queries current Spatie web memberships and permission pivots in MySQL. It avoids reliance on cached Gate guesses, previously loaded Eloquent relationships and client claims. Catalog and role graph changes clear the native Spatie cache after commit using the configured store. Rollbacks leave the previously valid cache intact. In-flight reads that already passed authorization may finish with their earlier result. Newly admitted requests read committed authorization. The credential revocation rules for disabling and password changes remain in effect.

Phase J should record the actor, target, timestamp and role or permission differences for successful security and assignment changes. Never include secrets, passwords, hashes, tokens or session data. The proposal to omit transient personal Kanban ordering and visibility events requires an explicit decision in J. Audit visibility and incident retention remain open.
