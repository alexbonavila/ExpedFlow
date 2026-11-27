# AGENTS.md — Development instructions

## 1. Authority and scope

These documents describe ExpedFlow. `docs/REQUIREMENTS.md` contains the product requirements. `docs/DEVELOPMENT_PLAN.md` defines the phases. The other documents establish technical contracts and rules. Proposals and pending technical decisions are identified separately from confirmed requirements. Identify any actual conflict and obtain approval before changing a confirmed requirement. A later explicit instruction from the project owner takes precedence. Update the relevant documentation accordingly.

## 2. Mandatory sequence

- A establishes Laravel and the base repository. B completes the testing and CI infrastructure. C implements the full functional database. Keep C work out of A and B.
- Implement business functionality only after B is accepted. Develop the functional frontend only after the backend is accepted in K. A minimal E2E test page is allowed in B if it contains no business logic.
- Each phase has its own scope, evidence and exit criteria. Keep phases separate. Failures, hidden exclusions and pending mandatory tests each prevent completion.

## 3. Implementation rules

- Follow KISS, DRY, SOLID, YAGNI, dependency injection, Laravel conventions and separation of responsibilities. Add abstractions only when a demonstrable need exists.
- Prefer official Laravel components, followed by established, compatible packages. Avoid duplicating existing capabilities. Justify new dependencies and check their versions, security and maintenance.
- Use REST API `/api/v1`, JSON responses and unpaginated API responses. Enforce authorization on the server for every request. Use real MySQL for integration tests. Use Sanctum and Spatie Laravel Permission. Support Spanish, Catalan and English from the start.
- Keep multitenancy, microservices, mandatory external services, notifications, document management, additional date features and other out-of-scope features out of the implementation.
- Keep secrets, real data and credentials out of Git, logs, fixtures and CI artifacts. Keep tests away from production Samba shares, MySQL instances and servers.
- Maintain protection settings, thresholds, exclusions and rules so failures remain visible. Keep tests enabled even when they fail.
- English is mandatory for source code, technical documentation, comments, identifiers, diagnostic messages, commit messages and pull requests. Localized user-facing content and localization tests are exempt.

## 4. Task procedure

1. Read the applicable documents and check the branch, working tree and previous results.
2. Identify the scope, acceptance criteria, risks and required tests. Preserve existing changes and other contributors' work.
3. Make the smallest change consistent with the design and add real tests in the same change. Update the documentation and API contract where applicable.
4. Run relevant local checks and all required CI checks before integration. Report exactly what could not be verified and why.
5. Review the diff, security, localization and regressions. Clear credentials, temporary files and unrelated changes from the delivery.
6. Report the changes, files, commands, actual results, limitations and acceptance status. Report only results, commits, pushes and checks that occurred.

## 5. Decisions and changes

Resolve implementation details within scope and document their consequences. If a decision changes business rules, public contracts, permissions or existing data, describe the change explicitly and obtain approval before making it a requirement. Record small technical decisions in the relevant existing documents to keep the file set focused. Do not start the next phase automatically.

## 6. Definition of done

Code and documentation agree, relevant tests are reproducible, mandatory gates pass, and there are no known regressions or undisclosed omissions. Acceptance requires evidence from checks that were actually run.

## 7. Branch management

- Use one implementation branch per phase. For Phase C, use `feat/database-schema-c`. Use descriptive commits for development blocks and one final pull request to `main`.
- Create auxiliary branches only when strictly necessary. Prefer local branches and verify that a remote auxiliary branch can be removed before publishing it.
- Integrate required, validated auxiliary changes into the implementation branch. Keep failed experiments and deliberate defects out of it.
- Preserve relevant validation evidence, then remove auxiliary branches once their purpose is fulfilled. Leave no redundant or validation-only branches at phase closeout. Respect branches belonging to other contributors or ongoing work.
- Never force-push shared branches or bypass main branch protection. Merge the final pull request only with explicit owner authorization.
- If remote branch deletion or pull request creation is unavailable, avoid unnecessary remote branches and document the exact manual operation.
- At Phase C delivery, the phase-related remote branches should be `main` and `feat/database-schema-c` pending owner integration.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
