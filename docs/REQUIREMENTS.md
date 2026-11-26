# ExpedFlow requirements

**Phase 0 — functional baseline.** The numbered requirements are agreed. Finalize the proposed implementation decisions in their respective phases without expanding scope.

## R01. Installation and scope

Each company runs one independent installation on its own server, with rack, RAID and SSD as appropriate for its infrastructure. Use Apache, PHP, Laravel and MySQL. Use a traditional installation. The company handles infrastructure, maintenance and backups. The GitHub repository is public. Cloud services are optional, and external telemetry is disabled by default. Provide a Vue/AdminLTE web application and a future Android app through the API. Multitenancy, external SSO and microservices are out of scope.

## R02. Identity, users and permissions

- Provide login, logout and password recovery by SMTP email. Public registration is disabled. Configure the initial administrator through `.env` and create the account through a secure installation procedure. The administrator creates the remaining users.
- Use Laravel Sanctum with sessions, cookies and CSRF for the first-party SPA and tokens for mobile clients. Manage roles and permissions with Spatie Laravel Permission. Administrators create users and roles and can configure permissions and statuses. All authorized ordinary users can access all cases, manage templates, soft delete and restore records. Only administrators can permanently delete records. Role configurability does not itself authorize privilege escalation.
- Store each user's language in MySQL as `es`, `ca` or `en`. Set the installation time zone in `.env`. Provide the interface, validation, application messages and email in all three languages. Keep user-authored text in its original language.

## R03. Clients

Maintain a client catalog. The required, unique name is its only functional field. Each case has a requesting client and can optionally have a different represented client from the same catalog. The catalog excludes tax ID (NIF), address, phone and email. Define technical name normalization rules in C.

## R04. Types, templates and cases

- A case type has a name, a unique two-character numeric code (`00`–`99`) that is immutable after assignment, and Laravel technical fields. Types classify cases and provide the basis for templates. Users can create and manage templates.
- Task templates for a type have a name, optional description and checklist. They have no preassigned users or dates. Using a template copies its data into independent tasks. Editing a template leaves existing cases intact. Type selection and use of that type's template are separate operations.
- A case has a generated code, required name, nullable description, type, requesting client, nullable represented client, status selected by the creator and multiple tags. It can contain zero or more tasks.
- Use `AAAANNNTT` without separators, exactly nine digits. The code consists of the year in the configured installation time zone at assignment, the global annual sequence 001–999 and the two-digit type code. Example `202600105` = 2026/001/05. Reset the sequence automatically each year. All types share the sequence. Never reuse an assigned code, even after deleting its case. Once the sequence reaches 999, block new cases and return an error. The code is immutable and unique even under concurrency.

## R05. Tasks, comments and checklists

- Each task belongs to one case. It has a required name, optional description, status selected by its creator, one optional date with an optional time, and a shared vertical order within its column. It can be assigned to zero, one or more users. All active tasks in the case are visible, with no personal hiding mechanism.
- Task comments have content, an author and technical timestamps. Users can edit and delete other users' comments. Record the operation and the actual actor.
- Each task has a checklist whose items have a name, completion marker and order. Items have no functional date. Subtasks with a separate calendar, priorities and unrequested fields are out of scope.
- Only tasks have a functional date. Separate start and end dates, independent deadlines, durations and reminders are out of scope. Laravel, audit and incident timestamps are technical metadata rather than user-entered functional dates.

## R06. Shared statuses

Cases and tasks share one catalog. The initial statuses, in order, have the Spanish labels `En espera`, `Pendientes`, `En curso`, `Paradas`, `Para verificar` and `Finalizadas`. Administrators can create, edit, reorder and delete statuses. A status cannot be deleted while cases or tasks reference it, including soft-deleted records that can be restored. Require an explicit initial status at creation, with no default. Dragging across columns changes the global status. Task statuses do not automatically determine case status.

## R07. Kanban and personal preferences

- The general Kanban board displays all active cases grouped by status. New cases appear at the bottom of their column for everyone. Each user can privately reorder cases vertically in each column and hide cases from their own general board. Users can show hidden cases again. Hiding affects board visibility only and leaves the case, its authorization and access from the general list intact.
- When a user moves a case to another status, it appears at the bottom of the new column for everyone else, with their visibility preferences preserved. The actor can place it at a chosen position. The actor's other personal ordering preferences are preserved.
- The task board for a case uses shared global statuses and vertical ordering. All tasks on the board remain visible. Drag tasks to reorder them or move them between columns.

## R08. Lists, tags and calendar

The general case table and applicable administration tables support search, filtering, sorting, UI pagination and CSV export. The client paginates table data from complete, unpaginated API responses. Tags have a unique name and configurable color stored in MySQL. Authorized users can create tags in a reusable catalog.

Each user's calendar shows only active dated tasks assigned to that user. A task with no time is an all-day event. A task with a time appears at that time, with no invented persisted duration. A task assigned to several users appears on all of their calendars. Notifications, automatic alerts and reminders are out of scope.

## R09. Deletion and restoration

Ordinary users can soft delete and restore records. Administrators can also permanently delete them. Soft deletion of a case includes its tasks. Restoring a case restores only tasks deleted by that same operation. Deleted tasks stay out of the ordinary Kanban board and calendar. Preserve assignments, comments and checklists for consistent restoration and keep dependencies of deleted parents inaccessible. Maintain relational integrity on permanent deletion and leave Samba files and folders intact. Define trash and catalog dependencies in C/E, and prevent deletions that break references.

## R10. Samba

- When creating a case, recursively copy the template folder for its type from the corporate Samba share to `EXPEDIENTES/<codigo> <nombre del expediente>`. Example `EXPEDIENTES/202600105 Proyecto eléctrico`. Make the template source and root configurable. The destination folder must preserve the code and following space.
- The API allows clients to upload photos and documents to the case folder through multipart requests and returns JSON. Keep files and individual file inventories out of MySQL. The web application cannot list, index, display, download or browse Samba content. Document management is out of scope. The application must never delete Samba files or folders, even during permanent case deletion.
- On a Samba failure or a failed operation, record an incident users can consult, including what happened, what it affected and when. Provide useful information while protecting secrets and sensitive paths. Incidents generate no email or push notifications.

## R11. Auditing and incidents

Record who made each relevant change, what changed and when. Store field changes and avoid unnecessary full copies. Retain audit history for one year with automated cleanup. Keep routine GET requests, navigation and reads out of the audit history and avoid duplicating technical logs. Store operational incidents in a separate table with operation, context, message and date. Keep them distinct from audit history and server logs. Incident retention remains undecided.

## R12. API, quality and technology

Use a versioned REST API with JSON for every API response and no response pagination. Document the API and make it available to authorized clients. Internet exposure is not implied. Complete the backend before the functional frontend. When implementing the Vue UI, use the latest compatible stable AdminLTE, with Kanban, calendar and tables. Prefer official Laravel mechanisms, then popular maintained packages. Follow KISS, DRY, SOLID, YAGNI and dependency injection. Automate tests and CI from B, before C. Run real E2E tests and use MySQL for integration.

## Proposed technical decisions

- Preserve the original Samba path after renaming a case and store only the operational reference needed. If Samba provisioning fails, retain the MySQL case, record an incident and allow an idempotent retry using the same sequence number. Validate this policy in I before implementation. Prevent overwriting or destroying existing files.
- Decide the date and time representation, name normalization, template model per type, rules for editing the type of an existing case, and handling of historical relationships in C. Keep the case code unchanged when editing its type and preserve the confirmed rules.
- Define the audit scope for personal preferences and incident retention in E/J. The proposal is to record business and security changes and omit transient personal ordering and visibility changes to reduce noise. Make an explicit decision before introducing that exclusion.
- Define copy failure recovery, retry mechanisms, upload limits and collision policy in I. A Samba browser interface is out of scope.
