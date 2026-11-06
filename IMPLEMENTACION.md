# Clínica Vida Salud

> **Referencia histórica de la implementación anterior.** El alcance vigente, la estructura modular y su correspondencia con `Nuevo parcial angelica.docx` están en [docs/ARQUITECTURA_Y_TRAZABILIDAD.md](docs/ARQUITECTURA_Y_TRAZABILIDAD.md). Ahora existen rol Paciente, registro online, vinculación verificada y auditoría. Citas y reportes están deshabilitados por defecto hasta integrar pagos. Las descripciones y resultados siguientes se conservan como antecedentes y no representan el estado actual.

Implementación Laravel / PostgreSQL de los 17 casos de uso de `../GUIA.html`. La guía se conserva como referencia; la aplicación no utiliza sus arreglos de prueba, selector libre de roles ni fechas simuladas.

## Ejecutar en Windows

Desde este directorio:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

Abrir <http://127.0.0.1:8000>. Los recursos de frontend ya están compilados. Tras modificar JavaScript o CSS:

```powershell
npm.cmd run build
```

No es necesario cambiar la política de ejecución de PowerShell para usar `npm.cmd`.

## Acceso y primer uso

La cuenta administrativa inicial usa `admin@vidasalud.bo`. Su contraseña aleatoria está únicamente en `storage/app/private/acceso-inicial.txt`, archivo ignorado por Git. Cambiarla desde **CU03 → Modificar**; una contraseña vacía conserva la existente. No publicar ese archivo ni `.env`.

En una instalación nueva sin administrador, `php artisan clinica:crear-admin correo@ejemplo.bo` crea la primera cuenta. Rechaza sobrescribir un administrador activo.

Orden sugerido para comenzar:

1. Administrador: registrar especialidades (CU05), médicos (CU04) y horarios (CU07).
2. Administrador: crear la cuenta de recepción y cuentas médicas (CU03). Cada cuenta médica debe vincularse a un profesional distinto.
3. Recepcionista: registrar pacientes (CU06), consultar disponibilidad (CU08) y programar citas (CU09).
4. Usar las cuentas correspondientes para gestionar agenda, asistencia y atención.

La aplicación inicia sin pacientes ni citas ficticias. Las cuentas usan contraseñas de al menos 10 caracteres y hash de Laravel. No existe autorregistro público.

### Datos de demostración

Se pueden añadir ejemplos ficticios con `php artisan db:seed --class=DemoDataSeeder`. La carga incorpora 4 especialidades, 5 médicos, 10 pacientes, 30 horarios semanales, 16 citas de distintos estados y una indisponibilidad que permite probar CU17. Las fechas se calculan al ejecutar la carga. Incluye ejemplos de citas futuras, atención, cancelación, inasistencia y reprogramación.

También crea una cuenta de recepción y cinco cuentas médicas si no existen. Sus contraseñas aleatorias se guardan en `storage/app/private/accesos-demo.txt`, excluido de Git. Los correos terminan en `@demo.example` y los CI/registros profesionales llevan el prefijo `DEMO`. Repetir la carga conserva los registros y contraseñas existentes y no duplica los ejemplos. No ejecuta migraciones ni vacía tablas. Estos datos se cargaron a petición del usuario.

## Funcionalidades y permisos

| Casos | Funcionalidad | Roles |
| --- | --- | --- |
| CU01–CU02 | Inicio y cierre de sesión real, cuenta inactiva, límite de intentos | Todos |
| CU03 | Crear/modificar usuarios, roles, vinculación médica y estados | Administrador |
| CU04–CU05 | Médicos con múltiples especialidades; especialidades | Administrador |
| CU06 | Pacientes, CI único, nacimiento, contacto y estado | Recepcionista |
| CU07 | Horario semanal por día, duración, indisponibilidades y vista previa de citas afectadas | Administrador |
| CU08–CU09 | Disponibilidad y reservas verificadas en PostgreSQL | Recepcionista |
| CU10–CU11 | Reprogramar conservando especialidad e historial; cancelar con motivo | Recepcionista |
| CU12 | Agenda filtrada; confirmación de citas para recepción | Recepcionista / Médico (solo agenda propia) |
| CU13 | Asistencia sin cambio de estado; inasistencia después de finalizar | Recepcionista |
| CU14 | Marcar citas de hoy como atendidas | Médico (solo agenda propia) |
| CU15 | Historial filtrado con todas las reprogramaciones y cancelación | Administrador / Recepcionista |
| CU16 | REP01–REP07, filtros, porcentajes, CSV e impresión | Administrador |
| CU17 | Citas vigentes que se cruzan con indisponibilidades; reprogramar o cancelar | Recepcionista |

## Persistencia y reglas

La conexión local utiliza el nombre exacto indicado por `DB_DATABASE` en `.env` (actualmente `CLÍNICA VIDA SALUD`). La base separada `clinica_vida_salud_test` se usa exclusivamente para pruebas. Las credenciales se leen del entorno y no se versionan.

Se aplicaron las migraciones originales de Laravel y `2026_10_24_000001_create_clinic_tables.php`: usuarios y vínculo profesional, médicos, especialidades, relación médico/especialidad, pacientes, horarios, indisponibilidades, citas y eventos de cita. Hay claves foráneas, índices únicos y restricciones de estado y de intervalos en PostgreSQL.

Las reservas y reprogramaciones usan transacciones y bloqueos del paciente y médico para serializar escrituras concurrentes. El servidor comprueba que el paciente, especialidad y médico estén activos, que exista la relación profesional, que el bloque pertenezca al horario y que no se cruce con otra cita del médico/paciente ni con una indisponibilidad. No permite reservar en el pasado. La duración corresponde al horario, sin fijarla a 30 minutos.

Una indisponibilidad no cancela citas automáticamente: las muestra en CU17. Los cambios de horario que invalidarían citas futuras se rechazan para permitir su gestión previa. No se eliminan registros clínicos; se activan/desactivan catálogos y usuarios. Las citas terminadas no admiten nuevas transiciones. La zona horaria es `America/La_Paz`.

Las rutas JSON de `/api/*` utilizan sesión web, CSRF y autorización en el servidor. Los datos se validan antes de persistir y el frontend escapa el texto mostrado. El CSV neutraliza fórmulas al exportar texto ingresado por usuarios. Los errores SQL no muestran consultas ni credenciales en las respuestas.

## Validación

Con la base de pruebas ya creada y las credenciales locales configuradas:

```powershell
php artisan clinica:preparar-pruebas
php artisan test
node --check resources/js/app.js
npm.cmd run build
php vendor/bin/pint --dirty --test
```

La preparación de pruebas aplica solo migraciones pendientes en la base de pruebas; no ejecuta `migrate:fresh`, truncados ni seeders. Las pruebas funcionales usan transacciones revertidas y comprueban el nombre de la conexión antes de insertar sus datos. Las pruebas de la página inicial y la prueba unitaria original se conservan.

La primera revisión automatizada cubre autenticación, permisos, cuentas inactivas, validación de catálogos, bloques y duración, solapamientos, restricciones de reserva, cancelación, historial, asistencia, inasistencia, agenda propia, indisponibilidades, cambios de horario y los siete reportes.

Resultado de la ejecución: **16 pruebas aprobadas, 155 aserciones**. Compilación Vite, revisión sintáctica de JavaScript, formato PHP y `git diff --check` correctos. La prueba HTTP real comprobó página y recursos con respuesta 200, inicio de sesión con CSRF, acceso autenticado, cierre de sesión y respuesta 401 posterior. PostgreSQL confirmó la conexión con la base local y versión 17.11. La base de la aplicación conserva únicamente la cuenta administrativa inicial, sin pacientes, médicos ni citas de prueba.

Queda pendiente la revisión visual manual de escritorio y móvil: el entorno de ejecución no proporcionó un navegador para esa comprobación.

No se realizaron operaciones sobre GitHub ni cambios en remotos o historial de Git.
