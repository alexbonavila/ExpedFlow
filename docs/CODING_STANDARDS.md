# Estándares de código

## Principios

KISS: menor solución que cumple requisitos. DRY: extraer duplicación real, no coincidencias superficiales. SOLID: responsabilidades, contratos y sustitución útiles, sin capas artificiales. YAGNI: no implementar futuros productos. DI: contenedor Laravel y dependencias explícitas. API First: reglas de negocio en servidor, no duplicarlas en Vue/Android. Convenciones Laravel prevalecen sobre arquitecturas personalizadas. TDD cuando ayude: prueba que falla, implementación mínima y refactor conservando comportamiento.

## PHP / Laravel

- Seguir convenciones PSR aplicables y Laravel Pint; formateador bloqueante. Nombres técnicos en inglés, claros y consistentes.
- Tipos de parámetros, retornos y propiedades donde sean aplicables; PHPStan/Larastan en nivel acordado en B. No silenciar errores con `mixed`, ignores o baselines indiscriminados.
- Controladores pequeños, Form Requests, API Resources, Policies y acciones/servicios concretos para operaciones complejas. Eloquent directo donde sea suficiente; scopes reutilizables para consultas.
- Usar transacciones, FKs, índices y validación de entrada. Prevenir mass assignment, N+1, carga de archivos completos en RAM y consultas sin necesidad.
- No usar `env()` fuera de archivos de configuración; no hardcodear credenciales, paths, idioma o zona horaria. Configuración cacheable.
- Errores esperados tipados/representados de forma coherente; registrar contexto mínimo sin secretos. No capturar excepciones para fingir éxito. Usar mecanismos Laravel para hashing, mail, filesystem, scheduler, eventos y fakes.
- Cambios de esquema mediante migraciones; no alterar una migración publicada para ocultar cambio. Seeders idempotentes, factories válidas y datos sintéticos. No sobrescribir configuración del usuario al actualizar.

## Vue / frontend

Vue 3, TypeScript propuesto, Composition API, Vite. Componentes pequeños y reutilizables. ESLint, Prettier y comprobación `vue-tsc`; usar API client común, manejo consistente de errores y estados de carga. Evitar estado global si estado local suficiente. No confiar en validación cliente para seguridad. Interacciones Kanban accesibles con alternativa a arrastrar; evitar HTML no sanitizado. Localización ES/CA/EN, claves coherentes, no textos de interfaz hardcodeados. Tablas con paginación cliente y CSV seguro.

## Dependencias y documentación

Preferencia: Laravel oficial > paquete popular/mantenido > implementación propia justificada. Verificar compatibilidad, vulnerabilidades, mantenimiento y tamaño. Lockfiles en Git. No añadir dependencias duplicadas. Documentar comandos y decisiones no obvias; comentarios explican por qué, no repiten el código.

## Calidad de las pruebas

Tests deterministas, independientes, datos explícitos, aserciones sobre resultado observable, permisos y persistencia. No testear detalles privados innecesarios, ni sustituir la operación probada por mocks. Usar fakes en fronteras externas y pruebas reales de integración cuando corresponde. Corregir flaky tests, no reintentar indiscriminadamente. Cada bug relevante incluye regresión; cada funcionalidad incluye casos positivos, negativos y límites. Ver TESTING y CI.
