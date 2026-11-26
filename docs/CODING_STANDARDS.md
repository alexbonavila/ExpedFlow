# Coding standards

## Principles

Use KISS to find the smallest solution that meets the requirements. Apply DRY to genuine duplication and leave incidental similarities alone. Use SOLID for useful responsibilities, contracts and substitutability, with no artificial layers. Apply YAGNI by deferring future product features. Use dependency injection through the Laravel container and make dependencies explicit. Keep business rules on the server in the API-first design to avoid duplicating them in Vue or Android. Follow Laravel conventions over custom architectures. Use TDD when useful by writing a failing test, making the smallest implementation and refactoring while preserving behavior.

## PHP / Laravel

- Follow applicable PSR conventions and Laravel Pint. Formatting failures block integration. Use clear, consistent English technical names.
- Type parameters, return values and properties where applicable. Use PHPStan/Larastan at the level agreed in B. Avoid suppressing errors with `mixed`, blanket ignores or indiscriminate baselines.
- Keep controllers small. Use Form Requests, API Resources, Policies and focused actions or services for complex operations. Use Eloquent directly when sufficient and reuse query scopes.
- Use transactions, foreign keys, indexes and input validation. Prevent mass assignment, N+1 queries, loading entire files into memory and unnecessary queries.
- Use `env()` only in configuration files. Keep credentials, paths, language and time zone configurable. Configuration must support caching.
- Type or represent expected errors consistently and log minimal context without secrets. Avoid catching exceptions to report false success. Use Laravel mechanisms for hashing, mail, filesystem access, scheduling, events and fakes.
- Make schema changes through migrations. Keep published migrations intact when making a new change. Use idempotent seeders, valid factories and synthetic data. Preserve user configuration during upgrades.

## Vue / frontend

Use Vue 3, the Composition API and Vite. TypeScript is proposed. Keep components small and reusable. Use ESLint, Prettier and `vue-tsc`. Share an API client and handle errors and loading states consistently. Use local state when it is sufficient. Enforce security on the server. Provide an accessible alternative to dragging for Kanban interactions and avoid rendering unsanitized HTML. Support ES/CA/EN with consistent keys and localized UI text. Use client-side table pagination and safe CSV export.

## Dependencies and documentation

Prefer official Laravel mechanisms, then popular maintained packages, then justified custom implementations. Verify compatibility, vulnerabilities, maintenance and size. Track lockfiles in Git and avoid duplicate dependencies. Document commands and non-obvious decisions. Use comments to explain the reason behind the code and avoid repeating what the code does.

## Test quality

Write deterministic, independent tests with explicit data and assertions on observable outcomes, permissions and persistence. Avoid unnecessary tests of private details and keep the operation under test real. Use fakes at external boundaries and real integration tests when appropriate. Fix flaky tests and avoid indiscriminate retries. Add regression coverage for each relevant bug and positive, negative and boundary cases for each feature. See TESTING and CI.
