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
