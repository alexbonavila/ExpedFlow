# AGENTS.md — instrucciones de desarrollo

## 1. Autoridad y alcance

Estos documentos describen ExpedFlow. `docs/REQUIREMENTS.md` contiene los requisitos de producto; `docs/DEVELOPMENT_PLAN.md` delimita las fases; los demás documentos fijan contratos y reglas técnicas. Las propuestas y decisiones técnicas pendientes se distinguen de los requisitos confirmados. Si existe un conflicto real, identificarlo y no alterar unilateralmente un requisito confirmado. Una instrucción explícita posterior del responsable prevalece; actualizar la documentación correspondiente.

## 2. Secuencia no negociable

- A: Laravel y repositorio base. B: infraestructura completa de pruebas y CI. C: toda la base de datos funcional. No anticipar C durante A/B.
- No implementar funcionalidades de negocio antes de aceptar B. No desarrollar frontend funcional antes de aceptar el backend (K). Una pantalla de prueba mínima para validar E2E en B sí está permitida, sin lógica de negocio.
- Cada fase tiene alcance, evidencias y criterio de salida. No mezclar fases ni declarar terminada una fase con fallos, exclusiones ocultas o pruebas pendientes obligatorias.

## 3. Reglas de implementación

- KISS, DRY, SOLID, YAGNI, inyección de dependencias, convenciones Laravel y separación de responsabilidades. No crear abstracciones sin una necesidad comprobable.
- Priorizar componentes nativos oficiales de Laravel; después paquetes consolidados y compatibles. No duplicar capacidades existentes. Justificar nuevas dependencias y comprobar versiones, seguridad y mantenimiento.
- API REST `/api/v1`, respuestas JSON, sin paginación de API. Autorización en servidor siempre. MySQL real en integración. Sanctum; Spatie Laravel Permission. ES/CA/EN desde el inicio.
- No añadir multitenancy, microservicios, servicios externos obligatorios, notificaciones, gestor documental, funcionalidades de fechas adicionales ni otras características ajenas al alcance.
- Nunca guardar secretos, datos reales o credenciales en Git, logs, fixtures o artefactos CI. Nunca operar sobre Samba, MySQL o servidores productivos durante pruebas.
- No modificar la configuración de protección, umbrales, exclusiones o reglas para ocultar errores. No desactivar tests para conseguir un resultado verde.

## 4. Protocolo de cada tarea

1. Leer los documentos aplicables y comprobar rama, estado del árbol y resultados previos.
2. Identificar alcance, criterios de aceptación, riesgos y pruebas necesarias. Respetar modificaciones existentes; no revertir trabajo ajeno.
3. Implementar el cambio mínimo coherente con el diseño; añadir pruebas reales en el mismo cambio. Actualizar documentación y contrato API si corresponde.
4. Ejecutar comprobaciones locales pertinentes y, antes de integrar, los controles exigidos por CI. Si no se pueden ejecutar, declarar exactamente qué queda sin comprobar y por qué.
5. Revisar el diff, seguridad, localización y regresiones. No dejar credenciales, archivos temporales o cambios no relacionados.
6. Entregar resumen de cambios, archivos, comandos ejecutados, resultados reales, limitaciones y estado de aceptación. No inventar resultados, commits, pushes ni checks.

## 5. Decisiones y cambios

Resolver detalles de implementación dentro del alcance, documentando sus consecuencias. Si una decisión cambia reglas de negocio, contratos públicos, permisos o datos existentes, dejarla explícita y obtener validación antes de convertirla en requisito. Usar decisiones técnicas pequeñas en los documentos correspondientes en lugar de proliferar archivos. No iniciar automáticamente la fase siguiente.

## 6. Definición de terminado

Código y documentación coherentes, pruebas relevantes reproducibles, gates obligatorios superados, sin regresiones conocidas ni omisiones encubiertas. La aceptación de una fase se basa en evidencias, no en un checklist marcado sin ejecución.
