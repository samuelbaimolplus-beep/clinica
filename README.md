# Clínica Vida Salud

Sistema académico en Laravel 13, PostgreSQL, Blade y JavaScript. La referencia funcional definitiva es `../Clinica_Vida_Salud_V6_26CU_Revision_Final.docx`, entregada por el equipo el 7 de noviembre de 2026. El desarrollo se ajusta a sus 26 casos de uso y acuerdos definitivos.

El [informe de implementación integrada V6](docs/IMPLEMENTACION_INTEGRADA_V6_2026-11-07.md) documenta los flujos programados, su trazabilidad y la configuración externa necesaria. El [registro previo de adecuación](docs/ADECUACION_WORD_V6_2026-11-07.md), los informes V3 y V5 y los demás documentos anteriores conservan valor histórico; sus preguntas y propuestas no sustituyen los acuerdos finales de V6.

## Ejecutar el proyecto

La entrega más reciente está descrita en el [informe de revisión funcional y Superadmin](docs/REVISION_FUNCIONAL_Y_SUPERADMIN_2026-11-07.md), con una [matriz de operaciones internas de los 26 CU](docs/MATRIZ_FUNCIONAL_V6_2026-11-07.md). Incluye navegación por rol, supervisión global y las diferencias corregidas después de la auditoría.

Con PostgreSQL iniciado y `.env` configurado, desde esta carpeta:

```powershell
php artisan migrate
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8000
```

Abrir <http://127.0.0.1:8000>. Las migraciones agregan o ajustan estructuras sin vaciar las tablas. No ejecutar `migrate:fresh` sobre la base del proyecto.

Se necesitan PHP 8.3 o superior con `pdo_pgsql`, Composer, Node.js y PostgreSQL. En una instalación nueva, ejecutar `composer install` y `npm.cmd ci`, copiar `.env.example` a `.env`, configurar una base existente y ejecutar `php artisan key:generate`. Después aplicar migraciones, compilar y crear el administrador con `php artisan clinica:crear-admin correo@ejemplo.bo`.

Los accesos iniciales y de demostración permanecen en archivos privados locales de `storage/app/private`, excluidos de Git. La aplicación no publica contraseñas.

## Funciones disponibles

- Superadmin: gestión de Administración y Recepción y supervisión global de los módulos. La reserva personal sigue correspondiendo al Paciente y la finalización de consulta al Médico responsable. Solo Superadmin puede gestionar otras cuentas Superadmin.
- Administrador: personal, médicos, especialidades y tarifas, horarios y porcentajes, historial, siete reportes, auditoría y resolución de reembolsos.
- Recepción: pacientes y vinculación de cuentas, disponibilidad, cobro presencial y ficha, reprogramación acordada, cancelación, asistencia, solicitudes de cambio y devolución, seguimiento, citas afectadas y operación de bloques CU24/CU25.
- Médico: agenda propia, ausencia al llamado, finalización de consulta y recomendación de seguimiento.
- Paciente: registro y verificación, recuperación de acceso, disponibilidad, reserva y pago online de pruebas, historial propio, solicitud de reprogramación y solicitud de reembolso de citas canceladas por Recepción.
- Acceso: sesión por rol, estado activo y revocación ante cambios de credenciales o permisos.

Los turnos protegen los primeros 120 minutos para presencial. El resto usa porcentajes enteros por médico, 50/50 inicial, mayor resto y desempate presencial, alternando modalidades mientras ambas conservan cupos. Los minutos sobrantes al final del turno quedan sin consulta; no se generan citas más cortas.

Los bloqueos de demanda se crean y liberan únicamente hoy, mediante selección y confirmación. Se conserva la cantidad de demanda registrada. No hay caducidad automática ni temporizador de advertencia. La habilitación online no admite retirada. «Mi perfil», edición propia de contacto y cambio propio de contraseña se retiraron por no corresponder a los flujos definidos; la recuperación funciona por correo.

## Superadmin

Para crear la primera cuenta en una instalación nueva: `php artisan clinica:crear-superadmin correo@ejemplo.bo`. El comando no reemplaza cuentas existentes y guarda el acceso aleatorio solo en `storage/app/private/acceso-superadmin.txt`, excluido de Git. Administrador no puede crear ni editar Superadmin.

## Correo

El registro de Paciente envía un enlace firmado de verificación. El inicio de sesión del paciente exige correo verificado. La recuperación utiliza un enlace temporal de un solo uso, mantiene contraseñas con hash y revoca las sesiones anteriores tras restablecerlas.

El entorno local está configurado con SMTP de Gmail y STARTTLS en el puerto 587, con el remitente Clínica Vida Salud. Se comprobó la conexión y autenticación SMTP; esta comprobación no envió un mensaje ni acredita su recepción. Las credenciales permanecen únicamente en `.env`, excluido de Git. La plantilla `.env.example` conserva `MAIL_MAILER=log` para instalaciones nuevas sin credenciales. Las pruebas automatizadas simulan las notificaciones y no envían correos externos. No copiar enlaces de recuperación ni credenciales a los informes o al repositorio.

## Pagos online y conciliación

Los módulos clínicos se habilitan con `CLINIC_APPOINTMENTS_ENABLED=true` (valor inicial de esta entrega). Antes de emitir fichas con cobro, Administración registra la tarifa en la especialidad. No se asignan tarifas ni pagos ficticios a registros preexistentes.

WizPay utiliza exclusivamente `https://api-test.wizpay.app`. Configurar `WIZPAY_TEST_API_KEY` y `WIZPAY_TEST_WEBHOOK_KEY` en `.env`, y el receptor público `/webhooks/wizpay` en el panel sandbox del proveedor, para los eventos `payment.qr.success` y `payment.qr.failed`, con el mismo secreto Bearer. Mantener en ejecución:

```powershell
php artisan schedule:work
```

El webhook encola la conciliación. Como respaldo, el programador revisa operaciones pendientes con espera creciente; también puede ejecutarse `php artisan clinica:conciliar-pagos`. Una respuesta del navegador nunca confirma un pago. Una transacción pendiente o desconocida conserva su retención hasta ser conciliada, incluso si vence el reloj local. La aprobación de un reembolso no marca la devolución como completada: se registra después la ejecución externa declarada conforme a CU26.

La integración está cubierta mediante respuestas HTTP simuladas en pruebas automatizadas; no se acredita un cobro real en el sandbox sin configurar y probar las credenciales del comercio. La documentación de configuración y las comprobaciones figuran en el informe enlazado.

La cola local usa `QUEUE_CONNECTION=sync`: el job se ejecuta en el proceso que lo despacha. Si se cambia a `database`, mantener también `php artisan queue:work`, además del programador.

## Organización del código

- `app/Http/Controllers` y `app/Http/Requests`: entrada, autorización y validación.
- `app/Services/Catalog`: catálogos e impacto de cambios administrativos.
- `app/Services/Schedules`: turnos, capacidad, modalidades, movimientos y afectaciones.
- `app/Services/Auth`: seguridad y revocación de sesión.
- `app/Notifications`: verificación de correo; recuperación mediante el broker de Laravel.
- `app/Observers` y `app/Services/Audit`: auditoría transaccional.
- `app/Support/ClinicAccess.php`: navegación por rol.
- `resources/js/modules`: pantallas separadas por responsabilidad.
- `database/migrations`: integridad, exclusión de superposiciones e historial.

## Verificación

Las pruebas usan exclusivamente `clinica_vida_salud_test`, mediante transacciones revertidas. `clinica:preparar-pruebas` aplica las migraciones pendientes de esa base aislada sin vaciar datos.

```powershell
php artisan clinica:preparar-pruebas
php artisan test --compact
node --test tests/Frontend/api.test.mjs
npm.cmd run build
php vendor/bin/pint --dirty --test
```

El resultado de la entrega figura en el [registro de adecuación](docs/ADECUACION_WORD_V6_2026-11-07.md). La verificación visual y las pruebas reales de correo y pasarela no se presentan como realizadas.

Las pruebas de navegador se ejecutan tras compilar mediante `npm.cmd run test:browser`. Usan Edge instalado en Windows y API simulada; no hacen pagos ni alteran pacientes. En otros sistemas se necesita instalar Chromium de Playwright. Las pruebas Feature comprueban el servidor y PostgreSQL.
