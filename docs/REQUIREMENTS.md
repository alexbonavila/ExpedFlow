# Requisitos de ExpedFlow

**Fase 0 — línea base funcional.** Los requisitos numerados son los acordados. Las decisiones de implementación indicadas como propuestas se concretan en la fase correspondiente sin ampliar el alcance.

## R01. Instalación y alcance

Una instalación independiente por empresa, en servidor propio (rack, RAID y SSD según infraestructura de la empresa). Apache, PHP, Laravel y MySQL. Instalación tradicional; la empresa se encarga de infraestructura, mantenimiento y backups. Repositorio público GitHub. Sin dependencia obligatoria de servicios cloud, sin telemetría externa por defecto. Aplicación web Vue/AdminLTE y futura Android mediante API. No multitenancy, SSO externo ni microservicios.

## R02. Identidad, usuarios y permisos

- Inicio y cierre de sesión; recuperación de contraseña por correo SMTP. Sin registro público. Administrador inicial configurado mediante `.env` y creado por procedimiento de instalación seguro; el administrador da de alta al resto.
- Laravel Sanctum, sesiones/cookies y CSRF para SPA propia; tokens para clientes móviles. Roles y permisos con Spatie Laravel Permission. Administradores crean usuarios y roles; pueden configurar permisos y estados. Todos los usuarios ordinarios autorizados acceden a todos los expedientes, administran plantillas y pueden eliminar lógicamente y restaurar. Solo administradores pueden eliminar definitivamente. No confundir roles configurables con autorización para elevar privilegios.
- Idioma individual guardado en MySQL: `es`, `ca`, `en`. Zona horaria de instalación definida en `.env`. Interfaz, validaciones, mensajes propios y correo en los tres idiomas. No traducir automáticamente textos escritos por usuarios.

## R03. Clientes

Catálogo de clientes; único campo funcional: nombre obligatorio y único. Cada expediente tiene un cliente solicitante y opcionalmente otro cliente representado, ambos del mismo catálogo. Sin NIF, dirección, teléfono ni correo del cliente. Las reglas técnicas de normalización de nombres se fijarán en C.

## R04. Tipos, plantillas y expedientes

- Tipo de expediente: nombre, código numérico de exactamente dos caracteres (`00`–`99`), único e inmutable una vez asignado, y campos técnicos Laravel. Los tipos sirven como clasificación y base de plantillas. Los usuarios pueden crear y administrar plantillas.
- Plantillas de tareas de un tipo: nombre, descripción opcional y checklist. No contienen usuarios preasignados ni fechas; al utilizar una plantilla se copian los datos a tareas independientes. Modificar una plantilla no modifica expedientes existentes. La selección del tipo y el uso de su plantilla son conceptos diferenciados.
- Expediente: código generado, nombre obligatorio, descripción nullable, tipo, cliente solicitante, cliente representado nullable, estado seleccionado por el creador, etiquetas múltiples. Puede tener cero o más tareas.
- Código: `AAAANNNTT` sin separadores, exactamente nueve dígitos; año de instalación en el momento de asignación (zona horaria configurada), correlativo anual global 001–999 y código del tipo de dos cifras. Ejemplo `202600105` = 2026/001/05. Reinicio anual automático. Correlativo común a todos los tipos, no reutilizar códigos ya asignados aunque se borre un expediente. Al agotarse 999, bloquear nuevas altas y devolver error. Código inmutable; unicidad garantizada también bajo concurrencia.

## R05. Tareas, comentarios y checklist

- Tarea asociada a un único expediente, con nombre obligatorio, descripción opcional, estado seleccionado por quien crea la tarea, una fecha opcional con hora opcional y orden vertical común dentro de su columna. Asignación a cero, uno o varios usuarios. Todas las tareas activas del expediente son visibles, sin mecanismo de ocultación personal.
- Comentarios de tarea: contenido, autor y marcas técnicas. Los usuarios pueden modificar y eliminar comentarios de otros usuarios, con registro de la operación y del actor real.
- Checklist dentro de cada tarea: elementos con nombre, marcado de completado y orden. Ningún elemento tiene fecha funcional. No añadir subtareas con calendario independiente, prioridades o campos no solicitados.
- Solo las tareas tienen fecha funcional. No hay fechas de inicio/fin separadas, deadline independiente, duración ni recordatorios. Las marcas técnicas Laravel, auditoría e incidencias no son fechas funcionales introducidas por el usuario.

## R06. Estados compartidos

Un único catálogo para expedientes y tareas. Inicialmente, en el orden indicado: En espera, Pendientes, En curso, Paradas, Para verificar y Finalizadas. Administradores pueden crear, editar, ordenar y eliminar estados; no se puede eliminar un estado mientras haya expedientes o tareas asociados (incluidos los eliminados lógicamente que puedan restaurarse). La selección inicial es explícita al crear; no imponer estado por defecto. Arrastrar entre columnas cambia el estado global. El estado del expediente no se calcula automáticamente a partir de sus tareas.

## R07. Kanban y preferencias personales

- Kanban general: todos los expedientes activos, agrupados por estado. Nuevos expedientes aparecen al final de su columna para todos. Cada usuario puede ordenar verticalmente los expedientes de cada columna de forma privada y ocultarlos solo de su propia vista general. Se pueden volver a mostrar. Ocultar no equivale a borrar ni a restringir autorización. Listado general conserva acceso a expedientes ocultos.
- Cuando un usuario mueve un expediente a otro estado, para los demás aparece al final de la nueva columna (sin cambiar sus preferencias de visibilidad). El usuario que lo mueve puede colocarlo en la posición elegida. El resto de sus ordenaciones personales se conserva.
- Kanban de tareas por expediente: orden vertical y estados globales, compartidos por todos; no se ocultan tareas. Arrastrar para ordenar o cambiar de columna.

## R08. Listados, etiquetas y calendario

Tabla general de expedientes y tablas de administración donde corresponda: búsqueda, filtrado, ordenación, paginación visual y exportación CSV. No paginar las respuestas de API; la paginación de la tabla es cliente. Etiquetas: nombre único y color configurable persistido en MySQL; catálogo reutilizable y creación por usuarios autorizados.

Calendario de cada usuario: solo tareas activas que tiene asignadas y tienen fecha. Sin hora: evento de día completo; con hora: evento a esa hora, sin inventar duración persistida. Tarea asignada a varios usuarios aparece en todos sus calendarios. No hay notificaciones, avisos automáticos ni recordatorios.

## R09. Eliminación y restauración

Usuario ordinario: Soft Delete y restauración; administrador: además eliminación definitiva. Eliminar lógicamente expediente implica eliminar lógicamente sus tareas; restaurar expediente restaura las tareas eliminadas por esa misma operación, no las previamente eliminadas por separado. No deben aparecer tareas eliminadas en Kanban ni calendario ordinarios. Las dependencias de tareas (asignaciones, comentarios y checklist) deben conservarse para una restauración coherente; no exponer dependencias de padres eliminados. Eliminación definitiva mantiene integridad relacional y nunca elimina carpetas ni archivos de Samba. Definir alcance de papelera y dependencias de catálogos en C/E sin permitir borrados que rompan referencias.

## R10. Samba

- Al crear expediente, copiar recursivamente la carpeta modelo correspondiente al tipo desde el recurso Samba corporativo a `EXPEDIENTES/<codigo> <nombre del expediente>`. Ejemplo `EXPEDIENTES/202600105 Proyecto eléctrico`. Origen de plantillas y raíz configurables; la carpeta de destino respeta el código y el espacio.
- API permite subir fotografías y documentos a la carpeta del expediente mediante peticiones multipart. Respuesta JSON. No guardar archivos ni inventario individual en MySQL. No listar, indexar, mostrar, descargar ni navegar Samba desde la web. No implementar gestor documental. No borrar archivos o carpetas Samba desde la aplicación, ni al borrar definitivamente un expediente.
- Fallo de Samba u operación fallida: registrar incidencia consultable por los usuarios, indicando qué ocurrió, respecto a qué y cuándo. La información debe ser útil y no revelar secretos o rutas sensibles. Sin correo o notificaciones push de incidencias.

## R11. Auditoría e incidencias

Registrar quién hizo cada modificación relevante, qué se modificó y cuándo; almacenar cambios de campos y no copias completas innecesarias. Historial de auditoría retenido un año y limpieza automatizada. No registrar cada GET, navegación o lectura. Evitar duplicación con logs técnicos. Incidencias operativas en tabla propia con operación, contexto, mensaje y fecha; distinguirlas de auditoría y logs de servidor. No se ha fijado plazo de retención para incidencias.

## R12. API, calidad y tecnología

API REST versionada, JSON para todas las respuestas API, sin paginación de respuestas. API documentada y accesible a clientes autorizados (no implica exposición pública en Internet). Backend completo antes de frontend funcional. AdminLTE en su versión estable compatible más reciente al implementar la UI, en Vue; Kanban, calendario y tablas. Preferir mecanismos Laravel oficiales, después paquetes populares y mantenidos. KISS, DRY, SOLID, YAGNI, DI. Pruebas y CI automatizadas desde B, antes de C. Probar E2E real y utilizar MySQL en integración.

## Decisiones técnicas propuestas (no nuevas reglas de producto)

- Conservar la ruta Samba original tras renombrar un expediente; guardar solo la referencia operativa necesaria, no archivos. Si falla el aprovisionamiento Samba, conservar el expediente MySQL, registrar incidencia y permitir reintento idempotente sin nuevo correlativo. Validar esta política en I antes de implementar. No permitir sobrescritura ni destrucción de archivos existentes.
- Resolver en C la representación de fecha/hora, normalización de nombres, modelo de plantilla por tipo, reglas de edición del tipo de expedientes existentes (no cambiar el código), y tratamiento de relaciones históricas. Mantener las reglas confirmadas.
- Concretar en E/J alcance de auditoría de preferencias personales y retención de incidencias; propuesta: registrar modificaciones de negocio y seguridad, no cambios efímeros de orden/visibilidad personal para evitar ruido, con decisión explícita antes de excluirlos.
- Definir recuperación de fallos de copia, mecanismo de reintento, límites de carga y política de colisiones en I. No introducir interfaz de exploración Samba.
