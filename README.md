# Clínica Vida Salud

Sistema académico de gestión de citas médicas construido con Laravel 13, PostgreSQL, Blade y JavaScript. La entrega actual comprende autenticación con cuatro roles, cuentas del personal, médicos, especialidades, pacientes, registro y vinculación de cuentas, varios turnos médicos, distribución de modalidades, bloqueos y aperturas de Recepción, consulta provisional de disponibilidad y auditoría consultable.

La revisión documental vigente es el [informe de concordancia con V5](docs/INFORME_CONCORDANCIA_V5_2026-11-07.md), con los 26 casos de uso, diferencias del programa, correcciones necesarias y preguntas pendientes. La última entrega de código corresponde al [avance V3 del 7 de noviembre](docs/INFORME_AVANCE_V3_2026-11-07.md). La revisión V5 no modifica funcionalidades: identifica, entre otras diferencias, que la retirada de aperturas online existente no está autorizada en la nueva línea base. Las reservas y los pagos todavía no están habilitados.

La [guía de arquitectura y trazabilidad](docs/ARQUITECTURA_Y_TRAZABILIDAD.md) explica la relación entre el documento del proyecto, casos de uso, reglas de negocio, clases y pruebas. También documenta las diferencias entre apartados antiguos y nuevos del Word, las decisiones de alcance y una secuencia para la defensa.

El equipo estableció CU01–CU05 y autorizó avanzar provisionalmente con la nueva V3. Eso no equivale a aprobar todos los demás casos de uso. La [revisión del Word del 4 de noviembre](docs/REVISION_WORD_2026-11-04.md) conserva el contraste histórico; los cambios posteriores se explican en el informe vigente.

Se autorizó de forma específica la [distribución de cupos de CU07 por mayor resto](docs/REGLA_CUPOS_MAYOR_RESTO.md), con prioridad presencial en empate exacto. Está disponible en **Horarios médicos → Distribución de cupos** y no supone dar por cerrado el resto de CU07.

El [ajuste de código a CU01–CU05](docs/AJUSTE_CU01_A_CU05_2026-11-04.md) documenta las correcciones ya implementadas: errores de autenticación/cierre, consulta de solo lectura, estados, especialidades activas, nombres normalizados y citas afectadas por cambios administrativos.

El [registro de uso del ZIP de referencia](docs/USO_DE_LA_GUIA_ZIP.md) explica qué ideas se adaptaron: perfil para los cuatro roles, cambio de contraseña, revocación de sesiones y conservación de correcciones de recepción al actualizar contacto.

## Ejecutar el proyecto existente

Desde esta carpeta, con PostgreSQL iniciado y `.env` configurado:

```powershell
php artisan migrate
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8000
```

Abrir <http://127.0.0.1:8000>. Las migraciones agregan estructuras pendientes sin vaciar las tablas. No utilizar `migrate:fresh` sobre la base del proyecto.

La cuenta administrativa inicial utiliza `admin@vidasalud.bo`. Su contraseña está únicamente en `storage/app/private/acceso-inicial.txt`, excluido de Git. Para una instalación sin administrador: `php artisan clinica:crear-admin correo@ejemplo.bo`.

## Instalación nueva

Se necesitan PHP 8.3 o superior con `pdo_pgsql`, Composer, Node.js y PostgreSQL. Instalar dependencias con `composer install` y `npm.cmd ci`; copiar `.env.example` a `.env`, generar `APP_KEY` con `php artisan key:generate` y configurar `DB_CONNECTION=pgsql`, host, puerto, base, usuario y contraseña de una base existente. Después ejecutar las migraciones, compilar y crear el primer administrador. No versionar `.env` ni los archivos privados de acceso.

## Organización y uso

- `app/Http/Controllers/Catalog` y `app/Http/Requests/Catalog`: entrada y validación independientes para usuarios, médicos, especialidades y pacientes.
- `app/Services`: operaciones de catálogos, registro/vinculación, horarios y auditoría.
- `app/Services/Auth`: cambio de contraseña propio y firma de sesión para detectar cambios de credenciales, rol o vínculos.
- `app/Support/ClinicAccess.php`: vistas disponibles por rol y comprobación de cuentas/perfiles activos.
- `app/Observers`: registro automático de modificaciones relevantes.
- `resources/js/modules`: formularios y pantallas de catálogos, horarios y cuentas de pacientes.
- `database/migrations`: integridad de datos y protección de la bitácora en PostgreSQL.
- `tests/Feature`: reglas de negocio, seguridad y regresión.

El administrador gestiona personal, médicos, especialidades, turnos y porcentajes, y consulta auditoría y citas afectadas. Recepción administra pacientes y sus códigos de vinculación verificados; también propone, confirma y libera bloqueos de demanda o habilita franjas protegidas para online. El paciente crea su cuenta, acepta términos y luego inicia sesión; puede consultar su perfil y disponibilidad online sin acceder a información de otros pacientes.

Todos los roles pueden abrir **Mi perfil → Cambiar contraseña**. Se verifica la contraseña actual; la sesión que realiza el cambio continúa y las demás deben volver a identificarse en su siguiente acceso.

Los horarios admiten varios turnos diarios sin superposición. Cada turno protege sus primeros 120 minutos para presencial; el resto usa 50/50 por defecto o porcentajes enteros configurados por médico, con mayor resto y desempate presencial. Se alternan las modalidades mientras ambas conservan cupos. El cálculo de capacidad no mueve citas existentes. Los cambios que afectan citas futuras se muestran en CU17 sin cancelarlas automáticamente.

## Funciones aplazadas

La consulta de disponibilidad funciona por rol y modalidad, excluyendo citas, indisponibilidades y bloqueos. Faltan retenciones de pago, consumo de bloqueos al formalizar citas, tarifas, verificación de pagos presenciales, WizPay en pruebas, reembolsos y seguimientos. También queda adaptar asistencia, atención, historial y reportes a las reglas nuevas. El código anterior de citas/reportes se conserva deshabilitado mediante `CLINIC_APPOINTMENTS_ENABLED=false`; habilitarlo no implementa esas reglas.

`IMPLEMENTACION.md` y los informes anteriores conservan antecedentes. La guía de arquitectura se complementa con el informe V3 vigente.

## Validación

Las pruebas requieren la base separada `clinica_vida_salud_test` y las credenciales locales de PostgreSQL. `clinica:preparar-pruebas` solo aplica sus migraciones pendientes. Los casos funcionales utilizan transacciones revertidas.

```powershell
php artisan clinica:preparar-pruebas
php artisan test
node --test tests/Frontend/*.test.mjs
node --check resources/js/app.js
npm.cmd run build
php vendor/bin/pint --dirty --test
```

La validación de la V3 y su resultado se registran en el [informe de avance](docs/INFORME_AVANCE_V3_2026-11-07.md#verificación-y-puesta-en-marcha). Las pruebas JavaScript también pueden ejecutarse con `npm.cmd test`. Queda pendiente la revisión visual manual porque no hubo un navegador conectado disponible.
