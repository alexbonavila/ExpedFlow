# Plan de desarrollo y aceptación

## Orden y dependencias

Fase 0: requisitos y documentación. A → B → C son estrictamente secuenciales. El backend D–K se acepta antes de L. El frontend funcional L–P se desarrolla sobre contratos validados. Q/R consolidan y distribuyen; S se definirá después. No iniciar automáticamente una fase posterior ni ejecutar cambios no solicitados. Paralelismo solo si se acuerda y no rompe dependencias ni contratos.

| Fase | Alcance y entregables | Criterio de salida |
|---|---|---|
| 0 | Documentación, requisitos, arquitectura, estándares, pruebas y plan | Documentos coherentes; propuestas técnicas distinguidas de requisitos; sin código funcional |
| A | Laravel base, GitHub, MySQL, configuración y entornos | Arranque local y conexión DB, repositorio y documentación, `.env.example` sin secretos, lockfiles, commit base |
| B | Testing, CI y controles de calidad completos | Todos los harnesses y gates comprobados, pruebas adversariales, protección main, evidencia real; ninguna funcionalidad de negocio anticipada |
| C | Base de datos completa | Diccionario final, migraciones, modelos, factories, seeders, FKs/índices/constraints y tests MySQL; migración y rollback comprobados |
| D | Autenticación y usuarios | Sanctum, sesiones/tokens, login/logout/reset, admin bootstrap, registro cerrado, gestión usuarios, locale, pruebas |
| E | Roles y permisos | Spatie, roles iniciales, roles configurables, Policies, matriz autorizaciones y pruebas de escalada/soft/hard delete |
| F | Clientes, expedientes, numeración | CRUD, clientes únicos, doble relación, código anual concurrente, 999 límite, estados de alta, soft delete/restore de expediente; coordinar cascada con G |
| G | Tareas, asignaciones, comentarios, checklist | CRUD, asignación múltiple, fecha única opcional, orden común, edición comentarios ajenos, cascada/restauración correcta |
| H | Estados, plantillas, etiquetas, preferencias | Catálogo común, integridad referencial, copia independiente de plantilla, colores/nombres únicos, comportamiento multiusuario Kanban |
| I | Samba e incidencias | Copia modelo, subida API, fallo/montaje ausente y recuperación, tabla incidencias; pruebas reales SMB; no listado ni borrado |
| J | Auditoría | Cambios de negocio y actor, cambios de relaciones, retención anual, scheduler, pruebas; asegurar auditoría de flujos previos |
| K | Consolidación API | Endpoints y políticas completos, OpenAPI, contratos y errores JSON, filtros sin paginación, integración backend, seguridad y regresiones |
| L | Infraestructura frontend | Vue + AdminLTE compatible, router, localización, cliente API, layouts, login; build y pruebas |
| M | Administración | Usuarios, roles/permisos, estados, plantillas y catálogo según permisos; E2E |
| N | Clientes y expedientes | Altas/ediciones, tipos, etiquetas, tablas, papelera, incidencias, exportación base; E2E |
| O | Kanban | General con orden personal/ocultación, tablero de tareas compartido, drag/drop y alternativa accesible; E2E dos usuarios |
| P | Calendario, tablas y CSV | Tareas asignadas día completo/hora, filtros/orden/paginación local, CSV, pruebas |
| Q | Validación integral | E2E completos, navegadores, accesibilidad, seguridad, rendimiento, fallos Samba y recuperación; calidad y regresiones |
| R | Instalación y versión estable | Apache/PHP/MySQL, SMTP, Samba, cron, instrucciones tradicionales, instalación/actualización comprobadas |
| S | Android futuro | Definir requisitos propios, usar contrato API y Sanctum; no desarrollar hasta recibir alcance |

Las fases F–J pueden necesitar usar modelos/catálogos ya creados en C aunque sus endpoints de administración lleguen después. No se considerará completada una regla transversal hasta verificarla en la fase que incorpora todas sus dependencias. La auditoría se consolida en J sin perder cobertura de modificaciones anteriores.

## Instrucciones específicas fase A

1. Verificar entorno y seleccionar versiones Laravel/PHP/MySQL/Node soportadas y compatibles. Registrar las versiones reales; no inventarlas. Crear proyecto Laravel limpio en raíz del repositorio, sin frontend funcional ni paquetes de dominio innecesarios.
2. Incorporar los doce archivos de fase 0. Adaptar documentación de arranque a comandos reales. Mantener archivos Laravel estándar y convenciones.
3. Configurar conexión MySQL de desarrollo y base de pruebas separada; `.env.example` con variables necesarias no secretas. Comprobar `.gitignore` y lockfiles. Preparar configuración para idioma/zona horaria sin introducir modelos funcionales.
4. Verificar arranque, migraciones base del framework en DB desechable y pruebas base existentes. Registrar comandos, versiones, resultados y commit. No instalar todavía el esquema ExpedFlow.
5. Confirmar repositorio GitHub y estado limpio; no afirmar push o CI sin comprobar. Cerrar A antes de iniciar B.

## Instrucciones específicas fase B

### B01 — Inventario y herramientas
Leer TESTING.md/CI.md. Seleccionar versiones compatibles, Pest/PHPUnit y plugins, Pint, Larastan, herramientas frontend, Playwright y auxiliares. Instalar solo dependencias necesarias y registrar lockfiles. Preparar entorno de test, scripts y documentación. Decidir umbrales medibles con justificación.

### B02 — Backend y base de datos
Configurar descubrimiento de suites, unit/feature/integración/arquitectura/contratos/regresión, cobertura y tipos, mutación. MySQL real y aislamiento. Pruebas ejecutables de migraciones base, rollback, factories/seed existentes, transacciones y concurrencia técnica. Configurar mecanismos Laravel de fakes y pruebas de almacenamiento/correo/console. No crear modelos de negocio.

### B03 — Frontend y navegador
Harness Vue mínimo, ESLint/Prettier/TypeScript, Vitest/Vue Test Utils. Vite build y servidor real efímero. Playwright en Chromium, Firefox y WebKit, axe-core, datos de prueba y capturas/trazas al fallar. El harness no se convierte en frontend funcional adelantado. Integrar contratos JSON y localización mínima verificable.

### B04 — Seguridad, rendimiento y almacenamiento
Auditorías Composer/npm, Gitleaks, CodeQL, Dependabot, ZAP, k6 y recurso Samba de prueba. Ejecutar smoke reproducible. No utilizar sistemas reales de empresa. Resolver compatibilidad/privilegios runner para SMB o documentar bloqueo sin cerrar fase.

### B05 — Workflows
Automatizar jobs PR/main, nocturnos y release, con permisos mínimos y artefactos. Usar scripts comunes local/CI, health checks y timeouts. Configurar gate agregador y protección de rama. Probar workflow manual de checks programados. No añadir CD a producción.

### B06 — Pruebas de los controles
Ejecutar matriz de fallos deliberados de CI.md en ramas temporales. Verificar detección, gate y bloqueo real de integración; revertir cada cambio. Verificar main final verde y limpio. Registrar URLs/IDs de ejecuciones reales, resultados, versiones y configuración efectiva de protección.

### B07 — Aceptación
Entregar inventario de herramientas, comandos, suites y workflows, resultados reales de local/CI, métricas, pruebas de gate, documentación de mantenimiento y categorías funcionales diferidas a C–Q. C solo se autoriza si B supera todos los controles obligatorios; un problema de permisos, runner o servicio no se convierte en un éxito simulado.

## Formato de cierre para cualquier fase

- Fase y commit/rama; alcance solicitado y entregado.
- Requisitos y criterios de aceptación cumplidos con evidencia.
- Archivos modificados, decisiones técnicas y justificación.
- Tests/comandos ejecutados y resultados (local y CI separados).
- Fallos, limitaciones y tareas diferidas legítimamente; ninguna afirmación de ejecución sin evidencia.
- Estado del árbol y de integración; recomendación de siguiente fase sin iniciarla automáticamente.

## Estado de este documento

Fase 0 documentada; A–S pendientes. No se han creado repositorio, proyecto Laravel, dependencias ni CI mediante este paquete. Las decisiones técnicas de implementación se cerrarán cuando correspondan, respetando los requisitos confirmados.

## Phase A and B status

Phase A is closed on the owner's supplied baseline evidence for commit `22528be`. The cloud reran the two inherited tests and Pint and confirmed the base Laravel page. The original cloud Vite configuration depended on a font CDN that the proxy blocked. The Phase B build now bundles the same font from the npm lockfile and ran locally.

Phase B was integrated through PR #1 at `f33b90f`. The default-branch push and actual manual extended workflow passed. Active main rules and the remaining controlled PR, scheduled and release-event evidence are recorded in [CI.md](CI.md). Phase C remains gated on explicit Phase B acceptance.
