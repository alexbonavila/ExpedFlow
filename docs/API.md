# Contrato API — reglas y recursos preliminares

## Convenciones

Base `/api/v1`, HTTPS, JSON UTF-8. API pública en especificación, no acceso anónimo a datos. No paginar respuestas, no `links/meta` de paginación, no límites silenciosos ni truncado. Búsqueda y filtros opcionales explícitos, ordenación con allowlist, paginación de tablas en Vue. Seleccionar campos y relaciones necesarios; evitar N+1. Riesgo de respuestas grandes: pruebas de carga y filtros explícitos, sin cambiar el contrato sin aprobación.

Solicitudes JSON salvo autenticación CSRF nativa y subida multipart. `Accept: application/json`. Respuestas de recursos mediante Laravel API Resources: propuesta `{"data": {...}}` o `{"data": [...]}`; acciones sin representación `{"message":"..."}` para evitar cuerpos no JSON. Errores consistentes `{"message":"...","code":"...","errors":{...}}` con códigos de máquina estables, mensaje localizado y errores de campos cuando proceda. Validar contrato exacto en K. No devolver HTML, redirects o stack traces para endpoints API. Los endpoints nativos auxiliares SPA (p. ej. CSRF) no forman parte del contrato v1.

| HTTP | Uso |
|---|---|
| 200 | Consulta/actualización/acción correcta con JSON |
| 201 | Creación correcta |
| 400 | Solicitud mal formada |
| 401 | No autenticado |
| 403 | No autorizado |
| 404 | Recurso inexistente o no accesible según contrato |
| 409 | Conflicto de estado, duplicidad operativa, secuencia agotada |
| 419 | CSRF inválido si procede en flujo SPA |
| 422 | Validación |
| 429 | Límite de peticiones |
| 503 | Servicio externo no disponible cuando impide completar la operación |
| 500 | Error inesperado, sin detalles sensibles |

## Autenticación

Sanctum: SPA propia usa sesión y CSRF; Android usa bearer tokens con expiración/revocación definida. No registro público. Login/logout, recuperación y reset mediante mecanismos Laravel/Fortify; endpoints de identidad documentados, sin enumeración de cuentas. `GET /api/v1/me` devuelve identidad, idioma y permisos necesarios, nunca credenciales. Gestión de usuarios, roles y permisos requiere privilegios. Policies y middleware obligatorios aunque frontend o token indiquen permiso.

## Mapa preliminar de endpoints

Las rutas exactas y esquemas se concretan en D–K; no es una API implementada. CRUD significa GET colección/elemento, POST, PATCH, DELETE según autorización. Restauración y eliminación definitiva son acciones explícitas distintas de DELETE ordinario.

| Recurso | Operaciones |
|---|---|
| `/clients` | Listar, crear, editar, eliminar/restaurar según reglas de integridad |
| `/expedient-types` | CRUD tipos; código inmutable |
| `/expedient-types/{id}/task-templates` | Administrar plantilla y checklist |
| `/statuses` | Listar; administración y orden por admin |
| `/expedients` | Listar, crear (opción copiar plantilla), consultar, editar, soft delete |
| `/expedients/{id}/restore`, `/expedients/{id}/force-delete` | Acciones explícitas con permisos |
| `/expedients/{id}/move` | Estado global y posición del actor |
| `/expedients/{id}/preferences` | Orden/visibilidad solo del usuario autenticado |
| `/expedients/{id}/tasks` | Listar/crear tareas |
| `/tasks/{id}` | Consultar, editar, soft delete |
| `/tasks/{id}/restore`, `/tasks/{id}/force-delete`, `/tasks/{id}/move` | Restaurar, eliminar definitivamente, mover posición global |
| `/tasks/{id}/assignees` | Gestionar asignaciones múltiples, sin duplicados |
| `/tasks/{id}/checklist` | CRUD/orden/marcado de elementos |
| `/tasks/{id}/comments` | CRUD comentarios, con auditoría del actor |
| `/tags`, `/expedients/{id}/tags` | Catálogo y asociación de etiquetas |
| `/calendar/tasks` | Tareas fechadas asignadas al usuario actual; filtro opcional de intervalo |
| `/expedients/{id}/uploads` | POST multipart, guardado Samba; sin GET de archivos ni DELETE |
| `/incidents` | Consultar incidencias operativas |
| `/users`, `/roles`, `/permissions` | Administración y asignaciones autorizadas |

## Fechas y códigos

Código `202600105` y código tipo `05` como strings. Una fecha funcional de tarea, propuesta `scheduled_date: "2026-12-01"`, `scheduled_time: "10:30"` o null; hora sin fecha inválida. Date-only no significa medianoche. Sin fechas en checklist ni plantilla. Timestamps técnicos ISO 8601. El cliente no decide el correlativo ni el año de generación.

## Movimientos y eliminación

El servidor calcula/valida estado y posición, en transacción. El cliente no puede editar preferencias ajenas mediante un user_id arbitrario. La API normal excluye soft deleted, papelera explícita y autorización para restaurar. Force-delete reservado a admin, nunca toca Samba. Validar hijos contra padre indicado y bloquear accesos a tareas de expedientes eliminados.

## Subidas y errores Samba

Validar tamaño, nombre y formato permitido; el servidor resuelve destino desde expediente, no aceptar rutas libres. No exponer ruta física, secretos ni contenido del archivo en JSON. Devolver resultado real de la escritura; registrar incidencia al fallar. Para alta de expediente con fallo de aprovisionamiento, propuesta: `201` con indicador explícito de fallo parcial/incidencia y reintento idempotente; no informar que la carpeta se creó si no ocurrió. Cerrar contrato en I/K. La exportación CSV se implementa en Vue a partir de los datos JSON, sin endpoints CSV dentro del API v1.

## OpenAPI y pruebas

Generar y validar especificación OpenAPI reproducible, con auth, esquemas, ejemplos sintéticos y errores. Preferir Scramble si compatible. Pruebas de contrato comparan respuestas reales con esquemas y verifican JSON, códigos, permisos, no paginación y ausencia de campos sensibles. Cambios incompatibles requieren revisión y estrategia de versionado. No publicar documentación con credenciales ni URL internas reales.
