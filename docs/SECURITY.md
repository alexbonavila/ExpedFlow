# Seguridad

## Modelo de amenazas

Aplicación interna no equivale a aplicación confiable. Usuarios autenticados pueden intentar elevar privilegios, acceder a preferencias ajenas, borrar definitivamente datos, modificar identificadores o enviar rutas/archivos maliciosos. Considerar robo de sesión/token, XSS, CSRF, inyección SQL, brute force, dependencias vulnerables, errores de montaje Samba y filtración de datos en logs o CI.

## Identidad y acceso

Sanctum, hashing nativo Laravel, regeneración de sesión al login e invalidación al logout, cookies Secure/HttpOnly/SameSite apropiadas, CSRF para SPA, tokens revocables para Android y duración definida. HTTPS incluso en LAN. Rate limit para login, reset, API y cargas según necesidades. Recuperación por SMTP con expiración y mensajes que no enumeren usuarios. Registro público ausente o deshabilitado. No almacenar contraseñas en texto claro.

Administrador inicial: variables `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` (propuesta) para inicialización mediante comando Artisan idempotente, con hash, validación y sin mostrar secreto. No sobrescribir una cuenta existente cada arranque; retirar credenciales de bootstrap tras usar. `.env.example` sin valores reales. Roles/permisos Spatie, Policies por recurso; comprobar siempre servidor y evitar asignación de privilegios por usuario no admin. Hard delete requiere rol administrativo además de permiso correspondiente; no delegar por accidente. Proteger acceso a hijos de expedientes borrados y preferencias de otro usuario. Revocar sesiones/tokens cuando se desactive un usuario según política definida en D.

## Entradas y salida

Form Requests, allowlists de campos y ordenación, Eloquent/queries parametrizadas, control mass assignment, límites de tamaño de petición, tipos y codificación. Escapar textos de usuarios, no renderizar HTML arbitrario de comentarios. En CSV evitar fórmulas interpretables por hojas de cálculo (celdas que comienzan por caracteres de fórmula), codificación y escapado correctos. API JSON sin trazas, SQL o rutas privadas; nunca serializar passwords, tokens ni datos de configuración. CSP y cabeceras HTTP seguras adecuadas; CORS mínimo (preferir mismo origen).

## MySQL y auditoría

Usuario MySQL de aplicación con privilegios mínimos necesarios; credenciales separadas de test. Restricciones DB además de validación. Auditar actor y diferencias, no contraseñas, hashes, tokens, cookies, credenciales o archivos. Retener un año mediante limpieza programada; no mezclar logs de petición con auditoría. Incidencias visibles con mensaje seguro y referencia útil, logs técnicos de acceso restringido. Evitar acumular datos personales innecesarios. Backups a cargo de empresa; documentar que restauración requiere coherencia entre configuración, MySQL y Samba.

## Samba y archivos

- Montaje fuera del docroot, cuenta de servicio de privilegios mínimos; preferir permisos SMB/ACL que impidan borrado de contenido existente cuando sea viable. La aplicación no ofrece acciones de borrado ni listado documental.
- Verificar montaje disponible y raíz autorizada antes de copiar/subir; evitar escribir en directorio local si Samba cae. No aceptar paths arbitrarios de cliente. Validar/sanitizar nombre de expediente y archivo, caracteres reservados, longitud, `..`, separadores, enlaces simbólicos y resolución de rutas. Mantener código y espacio en carpeta destino; tratar incompatibilidad explícitamente.
- Límites configurables, tipos permitidos según necesidad, streaming, no ejecutar archivos, no sobrescribir silenciosamente ni seguir enlaces fuera de raíz. Prever colisiones y fallo parcial sin eliminar documentos existentes. No guardar binarios en BBDD ni servirlos mediante Apache. Registrar incidencia segura ante error.
- No exponer credenciales SMB, ruta absoluta ni estructura de carpetas en API o UI. Solo endpoints de subida autorizados. Hard delete de expediente no invoca operaciones Samba.

## Producción y cadena de suministro

Apache document root `public/`, desactivar debug y directory listing, permisos de archivos mínimos, dependencias soportadas, configuración cacheable, logs rotados, scheduler por cron. Mantener secretos fuera de Git y artefactos. Auditorías Composer/npm, Gitleaks, CodeQL y ZAP en CI según CI.md; analizar y resolver alertas relevantes. No escanear sistemas de empresas sin autorización. Acciones CI con privilegios mínimos, datos sintéticos y runners aislados.

## Pruebas

Casos negativos para 401/403, CSRF, rate limiting, escalada de roles, IDOR, soft/hard delete, inyección, XSS, CSV, traversal, colisiones, montaje ausente, tokens revocados y datos sensibles no expuestos. Ver TESTING.md. La validación de seguridad final Q no sustituye controles desde B.

## Phase B checks and finding management

Run `composer audit --locked --no-interaction` and `npm audit --audit-level=high` against the checked-in lockfiles. Composer reports package advisories as failures. npm reports all severities and fails for high and critical findings. Use `bash scripts/testing/secrets.sh` for redacted, full-history Gitleaks scanning. Its single fingerprint allowlist entry covers a reviewed documentation row in the original Phase B inventory. The default rules and all other findings remain active. Dependabot checks Composer, npm and GitHub Actions weekly.

Build the frontend and use the guarded testing database before running `bash scripts/testing/zap.sh`. The pinned ZAP image runs the Automation Framework's passive baseline against the loopback Laravel process. The automation plan scopes scanning to this server, stores HTML and JSON under `test-results/zap/`, and exits nonzero on warning-or-higher findings according to its risk thresholds. Review findings by rule, target route and risk. Either remediate and rerun, or obtain owner approval for a documented disposition scoped to the finding. Avoid broad rule exclusions.

The cloud baseline ran ZAP 2.17.0 and exited 2. It reported two medium findings for CSP and anti-clickjacking headers and three low findings for cookie HttpOnly, X-Powered-By and content type sniffing protection. The cookie alert identifies `XSRF-TOKEN`, which Laravel exposes to the frontend for CSRF protection. Review its expected behavior and the deployment headers before deciding a disposition. No alert has been suppressed. The Composer audit could not query Packagist through the active proxy. npm audit found five moderate transitive findings in the dev-only Vue Test Utils chain, with no high or critical findings. The CLI's proposed forced downgrade changes the test utility's supported range. Reassess when the dependency chain has a compatible fix.
