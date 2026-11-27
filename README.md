# ExpedFlow

Self-hosted case, task and business workflow management system.

ExpedFlow is an internal Laravel application designed for self-managed Apache, PHP and MySQL installations. It will expose a versioned JSON API shared by its Vue web client and future Android client. The product will use an organization's Samba share for its files.

Phase A established the Laravel scaffold. Phase B integrated the test infrastructure and CI on protected `main`. The acceptance closeout and outstanding verification items are documented. See the [Phase B verification record](docs/CI.md) for execution results and open criteria.

## Development setup

Use PHP 8.4 with PDO MySQL, Composer, MySQL 8.4, and the Node LTS version in `.node-version`.

```sh
composer install --no-interaction
npm ci
npm run build
```

For local development, copy `.env.example` to `.env`, set your local database connection and generate an application key. Follow the separate [testing database setup and guard](docs/TESTING.md) before running database tests. Use synthetic data in the disposable test services.

```sh
composer lint
composer analyse
composer test
npm run lint
npm run format:check
npm run typecheck
npm test
```

The [test guide](docs/TESTING.md) also covers coverage, mutation, three-engine Playwright testing, accessibility, performance and the disposable CIFS mount. The [CI guide](docs/CI.md) describes job frequency, gate behavior and the branch rules. The [security guide](docs/SECURITY.md) describes the audits and passive ZAP baseline.

## Project documentation

| Document | Contents |
| --- | --- |
| [Agent instructions](AGENTS.md) | Repository rules for development agents |
| [Contributing](CONTRIBUTING.md) | Local validation, Git workflow and review |
| [Requirements](docs/REQUIREMENTS.md) | Confirmed product scope |
| [Architecture](docs/ARCHITECTURE.md) | Stack, responsibilities and integration |
| [Database](docs/DATABASE.md) | Planned schema and invariants |
| [API](docs/API.md) | Conventions and preliminary contracts |
| [Coding standards](docs/CODING_STANDARDS.md) | Quality and design conventions |
| [Development plan](docs/DEVELOPMENT_PLAN.md) | Phases, dependencies and acceptance criteria |
