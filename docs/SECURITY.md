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

The npm Dependabot updater failed in [run 45832034121](https://github.com/alexbonavila/ExpedFlow/actions/runs/45832034121) and again in [run 45850555534](https://github.com/alexbonavila/ExpedFlow/actions/runs/45850555534). Composer and GitHub Actions updates succeeded in the same update cycle as the later failure. Both public npm runs report a generic updater error. GitHub requires repository write access for the [first log](https://github.com/alexbonavila/ExpedFlow/network/updates/1732729864) and the [latest log](https://github.com/alexbonavila/ExpedFlow/network/updates/1732917379). The tested proposals passed under the current runtime. The updater's root cause remains unknown pending review of its detailed error.

The separately published Dependabot change excludes only routine major version updates for `@types/node`. Local YAML validation confirmed the targeted rule, the weekly schedules and the other ecosystem settings. The lockfile and manifest already use major 24, matching `.node-version`. Minor and patch updates within that major remain eligible, and the major-version filter leaves security updates eligible. Review the runtime pin and the declarations together when changing the Node major. GitHub has yet to run the updater with this change on `main`. [Dependabot configuration change](https://github.com/alexbonavila/ExpedFlow/commit/91926882d0ecf490ebbfe659da11f0bb16cf356b)
