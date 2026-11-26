# Contribución y flujo de desarrollo

## Flujo Git

Propuesta operativa: rama principal `main` protegida; ramas cortas `feat/...`, `fix/...`, `test/...`, `docs/...` o `chore/...`; pull request por cambio coherente. Commits descriptivos (preferentemente Conventional Commits). No force-push a ramas compartidas ni integrar cambios con CI obligatoria fallida. Configurar las protecciones efectivas en B y dejar evidencia de su funcionamiento.

## Antes de contribuir

Leer `AGENTS.md`, los requisitos y la fase activa. No incorporar funcionalidades de fases posteriores. Trabajar con entornos aislados, `.env` local excluido de Git y datos sintéticos. Instalar dependencias desde los lockfiles, no mediante actualizaciones indiscriminadas. Los comandos efectivos de instalación, pruebas y compilación se documentarán en A/B y se mantendrán sincronizados con CI.

## Pull request

Incluir objetivo y fase, alcance, cambios de datos/API, pruebas nuevas, comandos y resultados, documentación actualizada, impacto de seguridad y limitaciones. Para cambios de UI, comprobar ES/CA/EN y accesibilidad. Para migraciones, explicar actualización, rollback y posible pérdida de datos. Para correcciones, añadir una regresión reproducible cuando sea posible.

## Revisión

Comprobar alcance, simplicidad, autorizaciones, validaciones, transacciones, compatibilidad de contratos, tratamiento de errores y ausencia de secretos. No aceptar cambios exclusivamente porque aumenta un porcentaje de cobertura. No aprobar silenciosamente pruebas omitidas, flaky o con aserciones triviales. Las exclusiones de análisis requieren justificación concreta y revisión.

## Entrega de fase

Registrar rama/commit, lista de criterios de aceptación, comprobaciones ejecutadas y resultados, fallos pendientes y decisiones tomadas. No afirmar que un workflow se ejecutó si solo se validó su YAML localmente. Las versiones estables se publicarán con instrucciones de actualización y comprobaciones, sin desplegar automáticamente en instalaciones de empresas.
