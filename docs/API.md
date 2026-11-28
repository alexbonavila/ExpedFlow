# API contract — preliminary rules and resources

## Conventions

Use `/api/v1` as the base path, HTTPS and UTF-8 JSON. The API specification is public. Data access requires authentication. API responses must be complete, with no pagination, pagination `links/meta`, silent limits or truncation. Offer explicit optional search and filters, allowlisted sorting and table pagination in Vue. Select the fields and relationships needed to avoid N+1 queries. Mitigate large responses with load tests and explicit filters. Obtain approval for any contract change.

Use JSON requests except for native CSRF authentication and multipart uploads. Send `Accept: application/json`. Use Laravel API Resources for resource responses. The proposed formats are `{"data": {...}}` and `{"data": [...]}`, with `{"message":"..."}` for actions without a representation so their bodies remain JSON. Use a consistent error format `{"message":"...","code":"...","errors":{...}}` with stable machine-readable codes, a localized message and field errors when applicable. Validate the exact contract in K. API endpoints must omit HTML, redirects and stack traces. Native SPA support endpoints such as CSRF fall outside the v1 contract.

| HTTP | Use |
|---|---|
| 200 | Successful query, update or action with JSON |
| 201 | Successful creation |
| 400 | Malformed request |
| 401 | Unauthenticated |
| 403 | Unauthorized |
| 404 | Resource does not exist or is inaccessible under the contract |
| 409 | State conflict, operational duplicate or exhausted sequence |
| 419 | Invalid CSRF token where applicable to the SPA flow |
| 422 | Validation failure |
| 429 | Request limit reached |
| 503 | An unavailable external service prevents the operation from completing |
| 500 | Unexpected error, with sensitive details omitted |

## Authentication

Use Sanctum. The first-party SPA uses sessions and CSRF protection. Android uses bearer tokens with defined expiration and revocation. Public registration is disabled. Phase D uses Laravel sessions and the native password broker with Sanctum. Document identity endpoints and prevent account enumeration. `GET /api/v1/me` returns identity, language and the necessary permissions. It omits credentials. Managing users, roles and permissions requires privileges. Policies and middleware are mandatory even when the frontend or token indicates permission.

## Phase D identity contract

All paths in the table are beneath `/api/v1`. Use HTTPS. First-party browser requests use the same origin as `APP_URL`, with host-only cookies and no cross-origin CORS access. First call the native `GET /sanctum/csrf-cookie`. Send cookies and the decoded `XSRF-TOKEN` cookie value as `X-XSRF-TOKEN` on writes. Keep the session cookie HttpOnly and omit bearer credentials from browser storage. Mobile clients send a single `Authorization: Bearer <token>` header without browser cookies. Browser state takes priority when a request supplies both kinds of credentials.

| Method and path | Guard and input | Success |
|---|---|---|
| `POST /auth/login` | First-party session with CSRF. `email`, `password`. | 200 identity, regenerated session ID |
| `POST /auth/logout` | Active first-party session with CSRF. Empty body. | 200 `logged_out` |
| `POST /auth/token` | Email and password must be supplied even when already signed in. Also supply `device_name` of at most 100 characters. | 201 `{"data":{"token":"<issued-once>","expires_at":"<UTC timestamp>"}}` |
| `DELETE /auth/token` | Active bearer credential. Empty body. | 200 `token_revoked`, current token only |
| `POST /auth/forgot-password` | Public. `email`. | 200 `recovery_requested` for all validly formatted addresses |
| `POST /auth/reset-password` | Public. `email`, `token`, `password`, `password_confirmation`. | 200 `password_reset`, all previous sign-ins revoked |
| `GET /me` | Active session or bearer. | 200 identity |
| `PATCH /me/locale` | Active session or bearer. `locale` is `es`, `ca` or `en`. | 200 updated identity |
| `GET /users` | Active admin session or bearer. | 200 complete array of accounts in ascending ID order |
| `POST /users` | Active admin. `name`, `email`, `password`, optional `locale`. | 201 ordinary account, Spanish locale if omitted |
| `GET /users/{user}` | Active admin. Numeric account ID. | 200 account |
| `PATCH /users/{user}` | Active admin. Supplied fields can include `name`, `email`, `locale`. | 200 account |
| `PATCH /users/{user}/password` | Active admin. `password`, `password_confirmation`. | 200 `password_replaced` |
| `POST /users/{user}/disable` | Active admin. Empty body. | 200 disabled account |
| `POST /users/{user}/enable` | Active admin. Empty body. | 200 active account |

An account has `id`, `name`, `email`, `locale` and Boolean `active`. The identity adds `permissions`, a sorted list of effective permission names. Resources are wrapped in `data`. Actions return `message` and `code`. Unknown input fields are ignored. Names and emails are bounded at 255 characters. Emails are normalized before lookup or storage. New passwords require at least 12 characters, with a maximum of 72 bytes when using bcrypt. A bearer token defaults to 30 days and uses the `identity` ability. Server authorization still applies.

Identity responses send `Cache-Control: no-store`. Errors return `message`, `code` and optional field `errors`. All v1 errors are JSON even without `Accept: application/json`. An active authenticated user's locale controls messages. Public requests negotiate `Accept-Language` among ES, CA and EN and fall back to the configured application locale, then English. Error codes are stable across languages.

| HTTP | Error codes |
|---|---|
| 400 | `bad_request` |
| 401 | `unauthenticated`, `invalid_credentials` |
| 403 | `forbidden`, `https_required`, `session_required`, `bearer_required` |
| 404 | `not_found` |
| 405 | `method_not_allowed` |
| 409 | `email_conflict`, `self_disable`, `last_active_admin` |
| 419 | `csrf_mismatch` |
| 422 | `validation_failed`, `invalid_reset_token` |
| 429 | `rate_limited`, with retry information |
| 500 or 503 | `server_error` or `unavailable` respectively |

Login and token issuance share one rate-limit bucket. Recovery and reset share a separate bucket. Each defaults to 5 attempts per minute per normalized identity and IP, plus 30 per minute per IP. Recovery uses the native 60-second issuance throttle and 60-minute expiry. The future SPA consumes the token and email from the URL fragment at `/reset-password#token=<token>&email=<email>` and posts both fields with the new password. Fragments stay out of HTTP requests. The SMTP worker builds links from trusted `APP_URL`. The Phase D OpenAPI fixture covers these operations. [Identity OpenAPI](../tests/Fixtures/phase-d-openapi.json)

## Preliminary endpoint map

Phases D–K define the exact routes and schemas for this planned API. CRUD means GET for collections and individual resources, POST, PATCH and DELETE, subject to authorization. Restore and permanent deletion are explicit actions separate from ordinary DELETE.

| Resource | Operations |
|---|---|
| `/clients` | List, create, edit and remove subject to references from cases |
| `/expedient-types` | CRUD for types with immutable codes |
| `/expedient-types/{id}/task-templates` | Manage the template and checklist |
| `/statuses` | List statuses, with administration and ordering reserved for administrators |
| `/expedients` | List, create with an option to copy a template, retrieve, edit and soft delete |
| `/expedients/{id}/restore`, `/expedients/{id}/force-delete` | Explicit actions subject to permissions |
| `/expedients/{id}/move` | Update the global status and the actor's position |
| `/expedients/{id}/preferences` | Manage ordering and visibility for the authenticated user only |
| `/expedients/{id}/tasks` | List and create tasks |
| `/tasks/{id}` | Retrieve, edit and soft delete |
| `/tasks/{id}/restore`, `/tasks/{id}/force-delete`, `/tasks/{id}/move` | Restore, permanently delete and change the global position |
| `/tasks/{id}/assignees` | Manage multiple assignments and prevent duplicates |
| `/tasks/{id}/checklist` | CRUD, ordering and completion of checklist items |
| `/tasks/{id}/comments` | Comment CRUD with auditing of the actor |
| `/tags`, `/expedients/{id}/tags` | Tag catalog and tag associations |
| `/calendar/tasks` | Dated tasks assigned to the current user, with an optional date-range filter |
| `/expedients/{id}/uploads` | Multipart POST to save files to Samba. File GET and DELETE are unavailable |
| `/incidents` | Retrieve operational incidents |
| `/roles`, `/permissions` | Authorized administration and assignments in E |

## Dates and codes

Represent case code `202600105` and type code `05` as strings. A task has one functional date, proposed as `scheduled_date: "2026-12-01"` with `scheduled_time: "10:30"` or null. A time without a date is invalid. Treat a date-only value as distinct from midnight. Checklists and templates have no dates. Use ISO 8601 for technical timestamps. The server determines the sequence number and generation year.

## Moves and deletion

The server calculates and validates status and position within a transaction. A client cannot edit another user's preferences by supplying an arbitrary user_id. The ordinary API excludes soft-deleted records and provides an explicit trash view with authorized restoration. Force-delete requires the reserved role and an explicit grant on a trashed record and leaves Samba untouched. Validate child resources against the specified parent and block access to tasks belonging to deleted cases.

## Uploads and Samba errors

Validate the size, name and allowed format. The server resolves the destination from the case and rejects arbitrary paths. Omit physical paths, secrets and file contents from JSON. Return the actual write outcome and record an incident on failure. For case creation when provisioning fails, the proposed response is `201` with an explicit partial-failure or incident indicator and an idempotent retry. Report folder creation only when it occurred. Finalize the contract in I/K. Vue exports CSV from JSON data. API v1 has no CSV endpoints.

## OpenAPI and tests

Generate and validate a reproducible OpenAPI specification with authentication, schemas, synthetic examples and errors. Prefer Scramble if compatible. Contract tests compare actual responses with schemas and verify JSON, status codes, permissions, unpaginated responses and the absence of sensitive fields. Breaking changes require review and a versioning strategy. Keep credentials and real internal URLs out of published documentation.

## Phase E authorization contract

The existing HTTPS, Sanctum, active-account, real SPA CSRF, locale and `Cache-Control: no-store` rules apply. The endpoints below require both membership in the reserved `web` role `admin` and the listed effective permission. Token abilities cannot satisfy either condition. Only the source-controlled keys in the [permission matrix](SECURITY.md#phase-e-authorization-matrix) can be assigned.

| Method and path | Permission | Request and success |
|---|---|---|
| `GET /api/v1/permissions` | `roles.manage` | 200 with `data`, the complete catalog in declaration order. Each item has `key`, `group`, localized `label` and `description`, and boolean `restricted`. |
| `GET /api/v1/roles` | `roles.manage` | 200 with every web role in ascending ID order in `data`. |
| `POST /api/v1/roles` | `roles.manage` | `name` and `permissions` list. 201 with the new role in `data`. |
| `GET /api/v1/roles/{role}` | `roles.manage` | 200 with the web role in `data`. |
| `PATCH /api/v1/roles/{role}` | `roles.manage` | `name`. 200 with the renamed custom role in `data`. |
| `PUT /api/v1/roles/{role}/permissions` | `roles.manage` | `permissions` list. 200 with the updated role in `data`. |
| `DELETE /api/v1/roles/{role}` | `roles.manage` | 200 with localized `message` and `code: role_deleted` for an unassigned custom role. |

Each role exposes `id`, `name` and its assigned known `permissions` keys in ascending order. Custom names are user-authored, trimmed and limited to 125 characters. MySQL's `utf8mb4_unicode_ci` uniqueness for the web guard is case and accent insensitive. Reserved-name variants collide with their system role. Permission replacement accepts a complete JSON list of unique keys, including an empty list where permitted. Each essential administrator key must remain assigned to `admin`, and every other role is limited to delegable keys. The two system roles cannot be renamed or deleted.

Missing or non-web resources return `404 not_found`. Conflicts return `409 role_name_conflict`, `system_role` or `role_assigned`. Invalid or unavailable permission keys, duplicate keys and restricted grant violations return `422 validation_failed` with localized field errors. Guests and invalid credentials receive 401. Active clients without the required authority receive 403.

| Method and path | Permission | Request and success |
|---|---|---|
| `GET /api/v1/users/{user}/authorization` | `roles.assign` | 200 with the account's `data.roles` and `data.permissions`. |
| `PUT /api/v1/users/{user}/roles` | `roles.assign` | `role_ids` is the complete list of distinct positive integer web role IDs. 200 with the updated authorization in `data`. |

Assigned roles are objects with `id` and `name` in ascending ID order. `permissions` uses the same sorted effective keys as `/me`. It combines catalog grants from assigned web roles with existing direct web grants, subject to account state and the admin prerequisite for restricted keys. An account with no web role retains identity access only. Its direct grants are dormant until it is assigned a web role. A disabled account has no effective permissions. Assignments preserve existing direct grants and leave other guards outside the web API. There is no direct-grant write endpoint. Future resource checks also enforce parent scope, deletion state and personal ownership where applicable.

New accounts receive `user` atomically. Profile updates ignore role and permission input. Role replacement accepts an empty list for other accounts, including a disabled target, subject to the administrator membership invariant. Self-demotion and loss of the last active administrator are conflicts (`self_demotion`, `last_active_admin`). Changes apply on subsequent requests using existing SPA sessions and bearer tokens.

The [Phase E OpenAPI fixture](../tests/Fixtures/phase-e-openapi.json) covers the authorization additions. The [Phase D fixture](../tests/Fixtures/phase-d-openapi.json) continues to define the existing identity operations. Phase E uses positive canonical integer path IDs within the server's integer range. Invalid IDs return 404.

## Phase F clients, catalogs and cases

All routes are under `/api/v1`. They use the existing Sanctum and active-identity requirements, the actor's locale, JSON and `Cache-Control: no-store`. Collections are complete arrays under `data`. There are no pagination parameters, links or totals.

| Method and path | Permission and response |
|---|---|
| GET `/clients`, `/clients/{client}` | `clients.view`. List ordered by name, then ID, or a single client. |
| POST `/clients`, PATCH `/clients/{client}` | `clients.manage`. Supply `name`, the only business field. The returned client has `id`, `name`, `created_at` and `updated_at`. POST returns 201. |
| DELETE `/clients/{client}` | `clients.manage` and the catalog policy. Returns 200 with `message` and `code: client_deleted`. Any referenced client, including references from trash, returns 409. |
| GET `/expedient-types` | `expedient-types.view`. Ordered by two-digit `code`, then ID. Each item has `id`, `name` and string `code`. |
| GET `/statuses` | `statuses.view`. Ordered by `position`, then ID. Each item has `id`, localized `name` and `position`. Labels fall back to the configured locale, then the literal stored name. |
| GET `/expedients`, `/expedients/{expedient}` | `expedients.view` via the case policy. Active cases only. All authorized users have global scope. |
| POST `/expedients` | `expedients.create`. Returns a 201 case with its server-assigned code. |
| PATCH `/expedients/{expedient}` | `expedients.update`. Returns the updated active case. Type changes retain its assigned code. Status changes append to the destination and increment the column version. |
| DELETE `/expedients/{expedient}` | `expedients.delete`. Moves an active case and its active tasks to trash. Returns `message` and `code: case_deleted`. |
| GET `/expedients/trash`, `/expedients/trash/{expedient}` | `expedients.restore` or an eligible `expedients.force-delete` grant. Trashed cases only. |
| POST `/expedients/{expedient}/restore` | `expedients.restore`. Returns the active case after restoring the parent and its cascade-marked tasks. |
| POST `/expedients/{expedient}/force-delete` | Reserved administrator with `expedients.force-delete`. Permanently removes a trashed case. Returns `message` and `code: case_force_deleted`. |

Numeric path IDs use the canonical positive server integer range. Authorization precedes resource lookup. Wrong lifecycle scope, repeated actions and missing IDs return 404. Unauthorized callers receive 401 or 403 without confirmation of record existence. Valid session writes require CSRF. The error envelope also retains 419, 422, 429 where limited and safe 500 handling. Conflicts use `client_name_conflict`, `client_referenced`, `annual_sequence_exhausted`, `annual_sequence_inconsistent` or `case_order_exhausted` with localized messages. Invalid references, malformed input and an identical requester and represented client are field validation errors.

Trim Unicode boundary whitespace from names and accept 1–255 characters. Internal spacing, case, accents and authored text are preserved. Client names are unique under the database's case- and accent-insensitive collation. Create requires `name`, `expedient_type_id`, `requester_client_id` and an explicit `status_id`. `description` and `represented_client_id` are optional and nullable. PATCH changes only supplied fields. The resulting client pair must differ, including on a partial update. Write IDs are positive JSON integers. Description is plain text, with a 65,535 UTF-8 byte limit from MySQL TEXT. Technical fields supplied by clients are ignored. Phase F rejects explicit `template_id`, `copy_template`, `tags`, `tag_ids`, `move`, `preferences` and `uploads` fields. Future template, tags, board and upload operations will receive their own contracts.

Case responses contain numeric ID and foreign keys, string `code`, name, description, selected type, requester, represented client and localized status summaries, `column_position`, `column_version` and UTC ISO 8601 timestamps. Only trash includes `deleted_at`. The resource omits the storage path and hidden task markers. Active and trash lists accept ID equality filters for `status_id`, `expedient_type_id`, `requester_client_id` and `represented_client_id`. `q` matches a literal substring of name or code up to 255 characters. Sort by `code` (default), `name`, `created_at` or `column_position`. Use `direction=asc` (default) or `desc`. Ascending ID breaks ties. Personal board visibility has no effect on these lists.

Install the shared status catalog through the existing seeder. Before H, creating cases requires a type already provisioned in the deployment database under owner control. Phase F exposes read-only lookups and ships no example types or clients. [Verified OpenAPI fixture](../tests/Fixtures/phase-f-openapi.json)

## Phase G tasks and calendar

The [Phase G OpenAPI fixture](../tests/Fixtures/phase-g-openapi.json) describes the request and response shapes. All paths below are relative to `/api/v1`. Lists return all matching data without pagination. Successful resources use the `data` envelope. Creates return 201. Deletion actions return `message` and `code`. Other successful actions return 200.

| Method and path | Behavior |
| --- | --- |
| GET, POST `/expedients/{expedient}/tasks` | List or create active tasks. Lists sort by shared status position and ID, then task position and ID. |
| GET `/expedients/{expedient}/tasks/trash`, GET `/expedients/{expedient}/tasks/trash/{task}` | List or read independently trashed tasks in an active case. Lists sort by deletion time descending, then ID ascending. |
| GET, PATCH, DELETE `/tasks/{task}` | Read, update or independently soft-delete an active task. |
| POST `/tasks/{task}/move` | Set the shared status and optionally insert at a visible one-based index after removing the moving task. Omit `position` to append. Changed explicit moves compact the destination. Source positions and an unchanged order are preserved. |
| POST `/tasks/{task}/restore`, POST `/tasks/{task}/force-delete` | Restore or permanently delete an independently trashed task under an active case. |
| GET, PUT `/tasks/{task}/assignees` | Read current assignments or replace the entire set with `user_ids`. An empty list clears assignments. Summaries expose `id`, `name` and `active`. Existing disabled assignees can be retained or removed. |
| GET `/tasks/{task}/assignee-candidates` | List active users under the assign grant, exposing `id` and `name` only. |
| GET, POST `/tasks/{task}/comments` | List comments oldest first with ascending ID as a tie-breaker, or add a comment as the authenticated actor. |
| GET, PATCH, DELETE `/tasks/{task}/comments/{comment}` | Read, edit or soft-delete an active comment. An authorized peer can edit and delete it. |
| GET `/tasks/{task}/comments/trash`, GET `/tasks/{task}/comments/trash/{comment}` | List or read trashed comments. Lists sort by deletion time descending, then ID ascending. |
| POST `/tasks/{task}/comments/{comment}/restore`, POST `/tasks/{task}/comments/{comment}/force-delete` | Restore or permanently delete a trashed comment under an active task and case. |
| GET, POST `/tasks/{task}/checklist` | List items ordered by position and ID, or append an item. |
| GET, PATCH, DELETE `/tasks/{task}/checklist/{item}` | Read, edit or physically delete a checklist item. |
| PUT `/tasks/{task}/checklist/order` | Replace the order with `item_ids` containing each current item exactly once. A stale set returns 409. |
| GET `/calendar/tasks` | List the current actor's assigned active dated tasks in active cases, with inclusive optional `from` and `to` filters. Sort by civil date, null time first, then time and ID. |

Task creation requires a trimmed 1–255 character name and an explicit positive JSON integer `status_id`. PATCH applies supplied fields only. It also requires `tasks.reorder` when changing the status. Names preserve internal spacing. Optional description is nullable text up to 65,535 UTF-8 bytes. Civil dates use strict `YYYY-MM-DD` within MySQL DATE range `1000-01-01` to `9999-12-31`. Time accepts `HH:mm` or `HH:mm:ss`, and responses return seconds. Clear a task's date and any existing time in the same PATCH. The calendar exposes `all_day` to distinguish null time from explicit midnight. There are no task-list query filters in this phase.

Comment writes require a nonblank plain-text `body` with the same byte limit and preserve authored spacing. Comment responses expose the original author's ID and name. Checklist items use a trimmed 1–255 character name and a JSON boolean `completed`, default false. Their position is managed by the server, and completion has no effect on the task status.

Write bodies that describe resources or operation parameters use JSON objects. Accepted fields are allowlisted. Technical fields such as task parent, stored position and comment author are ignored. Task create and PATCH reject `assignees`, `user_ids`, `start_date` and `end_date`. Calendar requests use the authenticated actor and reject owner/user override fields. Task responses include a localized status, minimal assigned users and UTC ISO 8601 timestamps. Trash includes `deleted_at`. The database's internal position and case cascade marker stay private. Render authored fields as text.

## Phase H catalogs and personal Kanban

`GET /expedient-types/{type}` uses the existing type projection and view grant. The manage grant permits creation with a boundary-trimmed `name` and a two-digit ASCII string `code`. PATCH accepts only `name`. A duplicate code returns `type_code_conflict`. Deleting a type referenced by a case (including trash) or template returns `type_referenced`. Successful deletion returns `type_deleted`.

`GET /statuses/{status}` uses the existing localized status projection. Only a reserved administrator with `statuses.manage` may create, rename, reorder or delete statuses. Creation appends to the catalog; renaming clears the seeded translations. `PUT /statuses/order` takes the full current `status_ids` list and returns the reordered catalog. A stale list returns `order_membership_conflict`. Deletion returns `status_referenced` if any case or task, including trash, references the status.

`/expedient-types/{type}/task-templates` and its nested `/{template}/checklist` support list, detail, creation, PATCH, removal and `PUT /order` within their respective scopes. Both require type view plus template view for reads or template management for writes. Templates take `name` and optional plain-text `description` up to 65,535 UTF-8 bytes. Items take `name`. Order payloads contain the complete `template_ids` or `item_ids` in the selected scope. Responses expose IDs, names, positions and UTC timestamps, plus the parent ID and template description where applicable. Deleting a template removes its checklist blueprint only.

Case POST adds optional strict boolean `copy_template` (default false). With true, require an explicit existing `template_status_id`, including when the selected type has no templates. Omit that field otherwise. PATCH accepts neither copy field. Copying requires case creation, case view, task creation, type view and task-template view. If the templates contain checklist items, the existing checklist policy also requires task view and checklist management. The original case resource is returned with 201.

`GET /tags` and `GET /tags/{tag}` require tag view. Tag create, PATCH and removal use tag manage; the fields are a Unicode-boundary-trimmed `name` and `color` in six-digit RGB hex with a leading `#`, preserving hex letter case. MySQL compares names without case or accents. Tag deletion cascades its active and trashed case links. `GET /expedients/{expedient}/tags` requires case and tag view and returns tag resources. PUT requires case view and tag association and returns `data.tag_ids` in ascending ID order. A complete empty list clears links. Unknown IDs fail validation and leave the old membership intact. Standard F case payloads retain their existing fields. Tag filtering of the general case list is deferred to API consolidation in K.

`GET /expedients/board` requires case view and personal Kanban management. The complete response contains shared statuses with `id`, localized `name`, catalog `position` and `expedients`, each with `id`, `code`, `name` and the actor's `hidden` flag. The entries include hidden active cases. Valid actor ranks sort first by rank, then by base position and ID. Entries with absent or stale ranks follow by base position and ID. Status columns sort by catalog position and ID. Reads leave preferences unchanged.

`GET|PATCH /expedients/{expedient}/preferences` returns `expedient_id`, the actor's valid `position` or null, and `hidden`. PATCH takes only an explicit boolean `hidden`. `POST /expedients/{expedient}/move` takes a strict integer `status_id` and optional positive integer `position`. The requested index includes hidden entries and is applied after removing the moving case. Omission appends. Same-column moves update personal ranks. Cross-column moves also require case update and use the Phase F global status writer. The response contains the preference projection and `status_id`. The client must convert a visible-only drag position to the full column index.

### G09 combined task and catalog behavior

Tasks copied at case creation are immediately available through the Phase G task and checklist routes. Later template changes affect future copies. Global case moves retain each task's status and order. Personal board visibility applies to the case board, while ordinary task listing and the assigned calendar retain their own access rules.
