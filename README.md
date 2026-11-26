# ExpedFlow

Sistema autohospedado de gestión de expedientes, tareas y flujos de trabajo empresariales.

**Estado:** documentación de fase 0. No contiene todavía una instalación Laravel, código funcional ni workflows ejecutados.

## Objetivo

Aplicación interna de empresa, instalada en un servidor propio con Apache, PHP y MySQL. Una instalación por empresa. Backend Laravel con API REST JSON versionada y frontend Vue con AdminLTE. La futura aplicación Android consumirá la misma API. Los documentos se guardarán en el recurso Samba corporativo, sin convertir ExpedFlow en un gestor documental.

## Orden obligatorio de arranque

1. Fase A: crear Laravel y el repositorio GitHub; incorporar esta documentación; comprobar el arranque básico.
2. Fase B: configurar y validar todas las capas de testing, análisis estático, seguridad y GitHub Actions; comprobar que los controles detectan fallos deliberados.
3. Solo con A y B aceptadas: fase C, base de datos funcional completa.
4. Backend y contratos API completos y validados antes de desarrollar el frontend funcional.
5. Frontend, validación integral, instalación tradicional y, posteriormente, Android.

## Documentación

| Documento | Contenido |
|---|---|
| [AGENTS.md](AGENTS.md) | Instrucciones obligatorias para agentes de desarrollo |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Flujo de trabajo y entregas |
| [Requisitos](docs/REQUIREMENTS.md) | Alcance y reglas funcionales |
| [Arquitectura](docs/ARCHITECTURE.md) | Stack, responsabilidades e integración |
| [Base de datos](docs/DATABASE.md) | Diseño lógico e invariantes |
| [API](docs/API.md) | Convenciones y contratos preliminares |
| [Estándares](docs/CODING_STANDARDS.md) | Calidad y diseño del código |
| [Testing](docs/TESTING.md) | Matriz de pruebas, criterios y herramientas |
| [CI](docs/CI.md) | Workflows, quality gates y verificación |
| [Seguridad](docs/SECURITY.md) | Controles de seguridad |
| [Plan](docs/DEVELOPMENT_PLAN.md) | Fases A–S, dependencias y aceptación |

## Descripciones internacionales

- ES: Sistema autohospedado de gestión de expedientes, tareas y flujos de trabajo empresariales.
- CA: Sistema autoallotjat de gestió d’expedients, tasques i fluxos de treball empresarials.
- EN: Self-hosted case, task and business workflow management system.

La guía de instalación ejecutable, versiones efectivas y comandos definitivos se incorporarán en las fases correspondientes. No utilizar este paquete como si fuera una aplicación lista para desplegar.
