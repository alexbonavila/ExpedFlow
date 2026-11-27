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

Use Sanctum. The first-party SPA uses sessions and CSRF protection. Android uses bearer tokens with defined expiration and revocation. Public registration is disabled. Use Laravel/Fortify mechanisms for login, logout, recovery and password reset. Document identity endpoints and prevent account enumeration. `GET /api/v1/me` returns identity, language and the necessary permissions. It omits credentials. Managing users, roles and permissions requires privileges. Policies and middleware are mandatory even when the frontend or token indicates permission.

## Preliminary endpoint map

Phases D–K define the exact routes and schemas for this planned API. CRUD means GET for collections and individual resources, POST, PATCH and DELETE, subject to authorization. Restore and permanent deletion are explicit actions separate from ordinary DELETE.

| Resource | Operations |
|---|---|
| `/clients` | List, create, edit, delete and restore according to integrity rules |
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
| `/users`, `/roles`, `/permissions` | Authorized administration and assignments |

## Dates and codes

Represent case code `202600105` and type code `05` as strings. A task has one functional date, proposed as `scheduled_date: "2026-12-01"` with `scheduled_time: "10:30"` or null. A time without a date is invalid. Treat a date-only value as distinct from midnight. Checklists and templates have no dates. Use ISO 8601 for technical timestamps. The server determines the sequence number and generation year.

## Moves and deletion

The server calculates and validates status and position within a transaction. A client cannot edit another user's preferences by supplying an arbitrary user_id. The ordinary API excludes soft-deleted records and provides an explicit trash view with authorized restoration. Force-delete is reserved for administrators and leaves Samba untouched. Validate child resources against the specified parent and block access to tasks belonging to deleted cases.

## Uploads and Samba errors

Validate the size, name and allowed format. The server resolves the destination from the case and rejects arbitrary paths. Omit physical paths, secrets and file contents from JSON. Return the actual write outcome and record an incident on failure. For case creation when provisioning fails, the proposed response is `201` with an explicit partial-failure or incident indicator and an idempotent retry. Report folder creation only when it occurred. Finalize the contract in I/K. Vue exports CSV from JSON data. API v1 has no CSV endpoints.

## OpenAPI and tests

Generate and validate a reproducible OpenAPI specification with authentication, schemas, synthetic examples and errors. Prefer Scramble if compatible. Contract tests compare actual responses with schemas and verify JSON, status codes, permissions, unpaginated responses and the absence of sensitive fields. Breaking changes require review and a versioning strategy. Keep credentials and real internal URLs out of published documentation.
