# CI y Quality Gates — fase B

## Objetivo

Configurar GitHub Actions después de crear Laravel/repositorio (A), antes de cualquier migración de negocio (C). Integración automática de comprobaciones PHP, frontend, MySQL, seguridad y E2E; sin despliegues automáticos a instalaciones corporativas. La fase B debe demostrar tanto que el código válido pasa como que errores deliberados son rechazados.

## Workflows propuestos

| Job / workflow | Controles | Activación |
|---|---|---|
| backend-quality | Laravel Pint `--test`, PHPStan + Larastan, Composer validate, comprobación tipos | PR, push main, nocturno |
| backend-tests | Pest/PHPUnit: unit, feature, integración, arquitectura, regresión, cobertura | PR, push main, nocturno |
| database-tests | MySQL real, migraciones, rollback, modelos, concurrencia y constraints | PR, push main, nocturno |
| frontend-quality | ESLint, Prettier check, vue-tsc | PR, push main, nocturno |
| frontend-tests | Vitest, Vue Test Utils | PR, push main, nocturno |
| api-contract | OpenAPI reproducible y validación de respuesta real | PR, push main, nocturno |
| security | Composer audit, npm audit, Gitleaks, CodeQL (según configuración aplicable) | PR/push y programación según herramienta |
| build | `composer install` desde lock y build Vite desde npm ci | PR, push main, nocturno |
| e2e | Playwright, MySQL, servidor efímero y build de producción; Chromium/Firefox/WebKit | PR, push main, nocturno |
| accessibility | axe-core sobre páginas reales del harness/app | PR, push main, nocturno |
| storage-integration | Samba desechable, smoke y pruebas reales cuando existan | PR, push main, nocturno |
| mutation | Pest Mutation con informe y umbral acordado | Nocturno y release |
| performance | k6, presupuesto y conjunto de datos sintético | Nocturno y release |
| dynamic-security | OWASP ZAP contra instancia efímera autorizada | Nocturno y release |
| quality-gate | Agregador explícito de resultados obligatorios | PR, push main |

Dependabot configurado para Composer, npm y Actions, no es un test. Elegir cantidad de archivos YAML y jobs que mantenga la configuración simple; nombres estables. Los jobs obligatorios no deben desaparecer por filtros de paths que dejen el gate pendiente o falsamente verde. Los controles costosos pueden ejecutarse fuera de PR, pero deben estar configurados, probados y ser obligatorios para aceptación de B/release según su aplicabilidad. No declarar protección configurada sin comprobarla.

## Entorno y reproducibilidad

- Fijar versiones compatibles PHP, Laravel, Composer, Node, npm, MySQL, navegadores, dependencias y Actions; guardar lockfiles. Usar versiones soportadas y comprobar dependencias en el momento de A/B. Runners Linux, MySQL service con health check y credenciales exclusivas de CI; base única/worker cuando aplique.
- `composer install`, `npm ci`; cachés solo aceleran, no sustituyen instalación. Preparar APP_KEY efímera, config testing, DB de test; migrar desde cero. No usar secretos/infraestructura de empresas ni producción.
- Servidor E2E real con readiness HTTP y build compilado. Ejecutar tests en navegadores reales; no limitar a test de mock HTML. Configurar artefactos de trazas/capturas y logs al fallar. Validar Apache de referencia en Q/R.
- Servicio SMB efímero o runner aislado compatible; permisos mínimos, datos sintéticos. Si el montaje no puede ejecutarse en runner hospedado, resolver y verificar alternativa real de CI antes de dar por aceptado ese control; no sustituir silenciosamente por Storage fake.
- Scripts locales equivalentes a CI (propuesta Composer `test`, `test:coverage`, `analyse`, `lint`; npm `lint`, `typecheck`, `test`, `build`, `test:e2e`). Nombres finales documentados y probados. No depender de pasos manuales invisibles.
- Permisos GitHub mínimos, `pull_request` sin secretos privilegiados de forks; evitar ejecutar código no confiable con `pull_request_target` y privilegios de escritura. Actions fijadas a revisiones de confianza, timeout, concurrency/cancelación de ejecuciones obsoletas. Artefactos con retención acotada y sin secretos.

## Gate y protección

Configurar ruleset/protección de `main`: PR y checks obligatorios, impedir bypass ordinario y force-push. Gate agregador con `needs` y evaluación incluso tras fallos; falla ante cualquier dependencia obligatoria fallida, cancelada o inesperadamente omitida. No `continue-on-error` en gates bloqueantes. La política de revisión humana debe ser viable con el número de colaboradores; no fingir aprobación independiente.

Umbrales de propuesta: líneas PHP >=90%, cobertura de tipos PHP 100%, mutation >=80%, cero tests fallidos, cero errores de formato/estático/build, cero secretos y vulnerabilidades altas/críticas sin resolver. Validar aplicabilidad y valores en B; documentar exclusiones justificadas, alcance y comandos exactos. No rebajar requisitos unilateralmente. Dependencias con alertas deben evaluarse y resolverse antes del gate o de release según política documentada, sin ocultarlas. La cobertura de escenarios críticos exige casos explícitos. Un informe vacío no cuenta como éxito.

## Validación adversarial de CI (obligatoria en B)

Usar ramas temporales no integradas, cambios controlados y revertibles, sin secretos reales. Verificar que el workflow y el gate fallan por el motivo esperado y que una PR con ese fallo no puede integrarse mediante flujo normal. Restaurar cambios y verificar verde final.

| Fallo deliberado | Control esperado |
|---|---|
| Aserción unitaria falsa | backend-tests |
| Error de tipos PHP | backend-quality |
| PHP mal formateado | Pint |
| Migración inválida | database-tests |
| Regla arquitectónica incumplida | Pest Architecture |
| Respuesta JSON distinta del esquema | api-contract |
| Error de tipos/compilación Vue | frontend-quality/build |
| Aserción de componente falsa | frontend-tests |
| Aserción E2E falsa en navegador | e2e |
| Infracción accesible detectable | accessibility |
| Secreto sintético reconocido por regla de prueba (no credencial real) | Gitleaks |
| Caída bajo umbral de cobertura | coverage gate |
| Job obligatorio cancelado/omitido | quality-gate |
| Samba de prueba inaccesible | storage-integration detecta fallo y no escribe en fallback local |

Guardar referencia a ejecuciones y resultados; no dejar cambios de sabotaje, credenciales sintéticas detectables ni ramas temporales innecesarias en main. Validar también ejecución real de nocturnos/release con `workflow_dispatch` o equivalente, sin esperar a su horario. Comprobar que los informes se generan y que los fallos son visibles.

## Aceptación de B

Todos los checks aplicables ejecutados sobre commit concreto; harnesses reales PHP/MySQL/Vue/browser/contratos/seguridad; comandos locales documentados; programación y protección verificadas; pruebas adversariales con evidencia; main limpio y verde. Documentar las categorías que aún no tienen lógica funcional y sus pruebas futuras. No iniciar C si existen bloqueos de infraestructura o controles obligatorios sin demostrar.

## Phase B workflow configuration

`phase-b.yml` runs on pull requests, pushes to `main` and the Phase B feature branch, a daily schedule, release publication and manual dispatch. The schedule follows GitHub's UTC cron. All events require the core jobs. Scheduled, release and manual events also require mutation, performance and passive ZAP checks. On PR and push events the gate expects those three jobs to be skipped. The feature-branch push trigger allows CI evidence while authenticated PR access is unavailable. The aggregate gate fails if any required job fails, is skipped or is cancelled, or if the configured job list differs from the gate's list.

The backend test job runs each Pest suite once and captures line coverage, type coverage and JUnit. The `database-tests` and `api-contract` jobs verify the nonempty Integration and Contract suite outcomes from that report. Each job that uses MySQL gets a separate service. It provisions a random password for the restricted testing account and passes values to later steps through the GitHub environment file with masking. Keep all credentials and reports scoped to synthetic data. Frontend and browser jobs install from the npm lock. The E2E and accessibility jobs each install all three browser engines. CodeQL analyzes JavaScript and TypeScript in a dedicated job. Laravel PHP gets Larastan, Pest and the dependency audit.

The branch ruleset must require PRs and the observed `quality-gate` status, prevent force pushes and routine bypass, and use a feasible review policy for the repository's contributor count. Verify that code scanning is enabled for the repository and review the alert results independently of workflow completion. Select the code scanning protection rule for unresolved findings at the required severity where the GitHub plan supports it. Scheduled and release results remain part of phase acceptance even when a PR gate passes. Manual dispatch requires the workflow file on the default branch. The owner controls ruleset changes and integration.

## Deliberate failures observed locally

The following runs used the isolated `validation/phase-b-controls` checkout branched from `2c934ab` and a separate test MySQL container. Each defect was restored after its check. For the Gitleaks run the synthetic fixture was committed and reverted inside that local checkout. The synthetic commits were never published. The feature branch was published, but GitHub's API rejected the PR request and run queries with HTTP 403.

| Deliberate fault | Local result |
| --- | --- |
| Incorrect assertion in the database guard unit test | Pest exited 1 |
| Invalid value passed to a PHP string function | Larastan exited 1 with the argument type error |
| Invalid PHP spacing | Pint exited 1 and identified the affected construct |
| Exception in a baseline migration | The guarded MySQL suite exited 2 |
| Debug dump in application code | Pest Architecture exited 1 |
| Response status value outside the OpenAPI enum | The response validator rejected it and Pest exited 2 |
| Number-typed Vue ref initialized with a string | vue-tsc exited 2 |
| Changed confirmation in the Vue component | Vitest exited 1 |
| Incorrect page title expectation | Playwright Chromium exited 1 |
| Button with no accessible name | The Chromium axe scan reported `button-name` and exited 1 |
| Synthetic Gitleaks sentinel | Gitleaks exited 1 and identified the sentinel rule |
| Unit-only coverage below the 90% gate | The assertions passed, and the coverage minimum caused exit 1 |
| Required result failed, cancelled or skipped | The local aggregate script returned 1 for each outcome |
| Missing extended jobs on a scheduled event | The local aggregate script returned 1 |
| Empty CIFS mount point | The shared copy guard refused the operation and left the underlying directory empty |

The local gate also accepted the expected all-green PR and scheduled result sets. These local inputs exercise the aggregate's logic. Actual failed Actions jobs, blocked PR merge controls, and scheduled, manually dispatched and release runs need GitHub evidence. Run the full CIFS check on an isolated Linux runner with kernel CIFS and permitted mount capabilities. `bash scripts/testing/storage.sh --check-unmounted` exercises only the shared missing-mount guard.

## Candidate verification and acceptance

The local runtime checks ran against the code at `a8d5ab6`. Composer validation, Pint, Larastan, ESLint, Prettier, vue-tsc, Vite and actionlint passed. Pest executed 28 tests with 97 assertions, including four MySQL integration tests and one contract test. Line coverage was 662 of 662 executable lines across 26 source files, and the declaration-coverage report found zero uncovered lines across its 24 scanned files. Vitest passed one component test. Chromium passed the interaction and axe tests, and the production harness request returned HTTP 404. The workflow's MySQL provisioning script authenticated correctly in a local reproduction of the Actions service.

The repository's public branch inventory reported `protectedByBranchProtections=false` and no ruleset for `main`. The public ruleset list was empty. Authenticated GitHub API operations returned HTTP 403 through the cloud proxy. The feature branch push is authorized through the Git proxy. Branch rule administration and draft PR creation require authenticated access. Before integration, open the PR, verify the observed required check name and enforce it through the `main` ruleset with required PRs, force-push blocking and a feasible review count. Run and record scheduled, manual and release checks when the workflow is present on the default branch. Use an authorized owner review for integration.

The first two feature-branch runs ([45818569120](https://github.com/alexbonavila/ExpedFlow/actions/runs/45818569120) and [45819062188](https://github.com/alexbonavila/ExpedFlow/actions/runs/45819062188)) passed the backend, frontend, dependency audits, CodeQL execution, three-engine E2E and accessibility jobs. The container-side CIFS check returned exit 2 in both runs and the required aggregate returned exit 1. The push policy skipped mutation, performance and ZAP as expected. The second run used Composer 2.11.2, resolving the first run's warning about the upstream Composer 2.8.8 binary.

The full CIFS mount on the revised runner, open ZAP findings, scheduled and release evidence, actual failed-PR enforcement and branch protection remain acceptance criteria. The final candidate must be verified on a clean `main` after authorized integration. Domain models, business APIs, permission scenarios, and operational storage integration remain in their assigned later phases.
