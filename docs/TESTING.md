# Estrategia de pruebas

## Objetivo de B

Configurar antes de toda funcionalidad de negocio las herramientas, configuración, entornos, comandos reproducibles y ejecución CI de las categorías aplicables. Crear pruebas mínimas reales del proyecto base y de los harnesses. Las pruebas de dominio se escriben con su implementación; no crear casos vacíos, skips permanentes ni cobertura artificial. No existe obligación de usar simultáneamente herramientas duplicadas ni probar funciones que no existen.

## Matriz de pruebas

| Categoría | Herramienta preferente | Evidencia inicial en B / ampliación posterior |
|---|---|---|
| Unitarias | Pest/PHPUnit | Aserción real de comportamiento propio/base; reglas de dominio C–K |
| Feature / HTTP | Laravel HTTP Testing | Ruta base, respuesta y error; flujos API D–K |
| Integración | Pest + Laravel | Arranque de contenedor y servicios reales |
| MySQL / migraciones | Laravel DB Testing | migrate/rollback/migrate, constraints y conexión MySQL real |
| Modelos / relaciones / factories / seeders | Laravel + Pest | Validar elementos base existentes; totalidad del esquema en C |
| Transacciones / concurrencia | Pest + procesos/conexiones MySQL independientes | Demostrar commit/rollback y contención aislados; numeración y Kanban en F/H |
| API / contratos | HTTP assertions + OpenAPI validator | JSON de un endpoint mínimo y esquema verificable; contratos v1 en K |
| Autenticación / autorización | Laravel, Sanctum, Policies | Harness y guard base; sesiones, tokens, CSRF, roles en D/E |
| Validación / middleware | Laravel HTTP Tests | Error real de validación del harness; reglas de cada recurso |
| Artisan / scheduler | Laravel Console Tests | Comando base real; inicialización y limpieza en D/J |
| Eventos / listeners / jobs / colas | Laravel fakes + integración si se utilizan | Verificar capacidades del harness; pruebas de eventos y jobs efectivamente usados |
| Mail / recuperación | Mail/Notification fake | Verificar envío simulado sin SMTP productivo; flujo D |
| Filesystem | Storage::fake + integración | Escritura/lectura técnica en disco de prueba; Samba real en I |
| Samba real | recurso SMB desechable en runner compatible | Preparar servicio/fixture y smoke de montaje/copia aislada, sin datos reales |
| Localización | Laravel/Pest | Existencia de es/ca/en, paridad claves y fallback; mensajes de cada fase |
| Arquitectura | Pest Architecture | Regla aplicable real (p.ej. no debug en código propio, dependencias permitidas) |
| Regresión | Pest/Vitest/Playwright | Casos añadidos con cada corrección |
| Snapshots | Pest o herramienta del frontend | Solo salidas deterministas justificadas, revisiones explícitas |
| Cobertura / tipos | Pest + driver de cobertura; type coverage | Informes reproducibles, umbral calibrado sobre código propio |
| Mutación | Pest Mutation | Validar ejecución sobre lógica propia comprobable; no exigir mutaciones del framework |
| Frontend unitario / componentes / integración | Vitest + Vue Test Utils | Componente mínimo de harness, interacción y error de prueba real |
| E2E | Playwright Test | Navegador real contra servidor y build; navegación y resultado observable, ampliar por fase |
| Compatibilidad navegadores | Playwright Chromium, Firefox, WebKit | Smoke en los tres motores, matriz por funcionalidades |
| Accesibilidad | axe-core + Playwright | Escaneo real de página harness; teclado y flujo manual/automatizado en UI |
| Rendimiento | k6 | Smoke con presupuesto medible y sin saturar infraestructura; carga de datos realista en Q |
| Seguridad dinámica | OWASP ZAP baseline / pruebas HTTP negativas | Escaneo del entorno efímero autorizado; autenticación, CSRF e IDOR en fases funcionales |

Los fakes no sustituyen integración: las pruebas de numeración/concurrencia requieren MySQL real, y el almacenamiento SMB debe validarse realmente en un entorno aislado. SQLite opcional para casos no específicos del motor; nunca único motor de integración. No enviar correo ni archivos reales.

## Organización propuesta

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

Crear directorios y suites cuando tengan función real. Configurar descubrimiento de todos los tests, incluyendo suites no predeterminadas; comprobar que no quedan fuera de CI. Una única fuente de comandos compartidos entre local y CI (scripts Composer/npm propuestos). Configurar `.env.testing` sin secretos reales, DB desechable y guardas contra producción. Para paralelismo, bases/schemas aislados por worker; los tests concurrentes explícitos usan conexiones independientes sobre una misma DB de prueba. No envolverlos en transacciones invisibles para otras conexiones.

## Casos obligatorios por dominio

- C: migraciones desde cero y rollback, índices/constraints/relaciones, seed idempotente, factories, idioma, unicidad, fecha sin hora y hora sin fecha rechazada. Soft delete y restauración de modelos según alcance.
- D/E: login web, token móvil, logout/revocación, recuperación, registro público bloqueado, rate limit, CSRF, 401/403, escalada de privilegios, admin inicial idempotente. Usuario normal no puede hard delete aunque llame directamente a API; puede restaurar. Roles/permiso y políticas coherentes.
- F: clientes únicos y referencias, representado opcional, código nueve cifras con ceros, 001/999/1000, cambio de año y zona horaria, tipos de código único/inmutable, creación simultánea sin duplicados, eliminación no libera número.
- G: múltiples asignados y no duplicados, fecha nullable con hora opcional, comentarios ajenos editables y actor auditado, checklist sin fecha, restauración de expediente sin revivir tareas previamente borradas.
- H: catálogo compartido, estado seleccionado, eliminación de estado en uso impedida incluso soft deleted, plantillas copiadas independientes con checklist y descripción, etiquetas únicas/color, ocultación privada y listado completo, nuevo expediente al final, cambio de columna al final para otros usuarios, orden personal y orden común de tareas, concurrencia.
- I: copia recursiva correcta, montaje ausente, carpeta de origen ausente, colisión, fallo parcial, permisos, reintento sin duplicidad, subida válida y rechazo de traversal/oversize/symlink, no borrar ni listar Samba, incidencia persistida y respuesta honesta. Probar que una carpeta local bajo punto desmontado no recibe archivos accidentalmente.
- J: actor, fecha, diferencias, cambios de relaciones y comentarios, redacción de secretos, borrado/retención de auditoría al año con límites temporales y scheduler. Incidencias útiles y separadas de auditoría.
- K: cobertura de endpoints y permisos, JSON incluso en errores, no paginación, OpenAPI sincronizado, filtros/orden permitidos, no exposición de datos sensibles.
- L–P: flujos E2E de login, recuperación en entorno simulado, admin/usuario, clientes, expedientes, plantillas, Kanban dos usuarios, papelera/restauración, tareas múltiples, comentarios y checklist, calendario día completo/hora, CSV, idiomas, incidencias. No navegador de Samba.
- Q/R: instalación limpia y actualización, navegadores, teclado, accesibilidad, carga, seguridad, smoke de Apache y configuración productiva de referencia; documentación reproducible.

## Cobertura y gates

Propuesta de objetivos iniciales a validar en B: cobertura de líneas PHP propias >=90%, tipos PHP medibles 100%, mutation score >=80%, 100% de escenarios críticos definidos ejecutados (no equivale a 100% de líneas), cero fallos y errores de análisis, cero vulnerabilidades altas/críticas sin resolver. Definir denominadores, rutas incluidas, driver, informes y casos sin código medible; no excluir código propio complejo para inflar valores. Mutation/type coverage no son sustitutos de buenas aserciones. Umbrales numéricos propuestos, no requisitos de producto previamente aceptados; registrar los valores definitivos y su justificación en B. Gates operativos y no vacíos antes de C.

## Evidencia

JUnit, cobertura, informes análisis, trazas/capturas E2E solo con datos sintéticos, resultados k6/ZAP, logs redactados. Registrar comandos, versiones, commit y resultados. No presentar tests planificados como ejecutados.

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

### Mutation baseline and acceptance

The full mutation command generated 647 mutations across 24 files. Pest detected 75, reported 567 surviving and five uncovered, and returned exit 1 at 11.59% against the 80% minimum. Configuration files accounted for 511 survivors, including scaffold defaults outside the selected MySQL workflow. Another 51 survivors were in schema migrations, factories or the seeder. Line and declaration coverage use separate criteria and passed. The mutation threshold remains in effect. Improving the score requires observable tests for supported behavior or an explicit owner decision about the scope. Assertions that only repeat scaffold defaults would not validate application behavior.

### Frontend and browser workflow

Use the Node version in `.node-version`, install with `npm ci`, then run `npm run lint`, `npm run format:check`, `npm run typecheck`, `npm test` and `npm run build`. Vue 3 and its Vite plugin compile the fixture. TypeScript and vue-tsc verify types, ESLint and Prettier verify source quality, and Vitest with Vue Test Utils and jsdom exercises observable form behavior. Instrument Sans comes from the locked Fontsource package, so the build can bundle the same font without an external build-time download.

Install Playwright's Chromium, Firefox and WebKit with `npx playwright install chromium firefox webkit`. On Linux CI add `--with-deps` for browser libraries. Start with compiled assets and the test MySQL configuration above. `npm run test:e2e` and `npm run test:a11y` start their own guarded Laravel process on port 8001 and exercise all three browser projects unless a project is explicitly selected. Axe runs against a visible validation error, and the interaction spec exercises keyboard navigation and submission. Keep port 8001 free. Test failures retain screenshots and traces in `test-results/playwright/`. Playwright also writes an HTML report.

The cloud ran the component test, Vite build, lint, formatting and type checks. Chromium 156 from the system installation passed the browser and axe specifications using `PLAYWRIGHT_CHROMIUM_EXECUTABLE=/usr/bin/chromium`. Requests to the browser harness under `APP_ENV=production` returned HTTP 404. A temporary incorrect confirmation string produced a Vitest assertion failure and was restored. The cloud proxy denied Playwright's browser downloads. Playwright-managed Firefox and WebKit still require an execution environment with access to the browser distribution.

### Performance and SMB smoke checks

With built assets and the testing database available, run `bash scripts/testing/performance.sh`. This Linux Docker check sends three virtual users to the ephemeral Laravel welcome page for five seconds. The baseline observed 56 requests, no errors and a p95 of 266.48 ms. The gate uses a p95 below 500 ms, fewer than 1% HTTP errors and at least 99% successful status and content assertions. A concurrent workload run exceeded p95 at 1.33 s and correctly failed. The subsequent run observed 60 requests, no errors, 120 passing assertions and a p95 of 299.11 ms. Run it on a dedicated runner without simultaneous mutation or image builds. Reports go to `test-results/performance/`.

Use `bash scripts/testing/storage.sh` on an isolated Linux runner with Docker, passwordless sudo, `cifs-utils` and kernel CIFS support. The GitHub-hosted job installs the helper and loads the kernel module. Samba runs in a disposable container with an SMB3 listener bound to loopback. The runner mounts the test share using generated writer and reader accounts, verifies I/O and permissions, stops Samba, then checks that an unavailable share leaves the underlying directory empty. For the guard alone, run `bash scripts/testing/storage.sh --check-unmounted`. macOS developers need an isolated Linux VM or a hosted runner for a real CIFS mount.

The first two hosted attempts used a container-side mount. The managed cloud rejected the container mount capability, and the hosted CIFS smoke returned exit 2. The revised implementation performs the mount on the isolated Linux runner and preserves stage diagnostics. Its loopback Samba server and missing-mount guard were exercised locally. The revised full mount check passed in [GitHub Actions run 45819539665](https://github.com/alexbonavila/ExpedFlow/actions/runs/45819539665).
