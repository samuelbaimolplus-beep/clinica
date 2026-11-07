# Clínica Vida Salud

Sistema académico en Laravel 13, PostgreSQL, Blade y JavaScript. La referencia funcional definitiva es `../Clinica_Vida_Salud_V6_26CU_Revision_Final.docx`, entregada por el equipo el 7 de noviembre de 2026. El desarrollo se ajusta a sus 26 casos de uso y acuerdos definitivos.

El [registro de adecuación a V6](docs/ADECUACION_WORD_V6_2026-11-07.md) documenta las correcciones efectivas, funciones retiradas, trazabilidad y verificación. Los informes V3 y V5 y los demás documentos anteriores conservan valor histórico; sus preguntas y propuestas no sustituyen los acuerdos finales de V6.

## Ejecutar el proyecto

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

- Administrador: usuarios del personal, médicos, especialidades, turnos, indisponibilidades, porcentajes y consulta de auditoría.
- Recepción: pacientes, vinculación verificada de cuentas, consulta presencial de disponibilidad, citas afectadas, CU24 para bloqueos de hoy y CU25 para habilitar franjas protegidas online.
- Paciente: registro, verificación de correo, recuperación de acceso y consulta de disponibilidad online.
- Acceso: sesión por rol, estado activo y revocación ante cambios de credenciales o permisos.

Los turnos protegen los primeros 120 minutos para presencial. El resto usa porcentajes enteros por médico, 50/50 inicial, mayor resto y desempate presencial, alternando modalidades mientras ambas conservan cupos. Los minutos sobrantes al final del turno quedan sin consulta; no se generan citas más cortas.

Los bloqueos de demanda se crean y liberan únicamente hoy, mediante selección y confirmación. Se conserva la cantidad de demanda registrada. No hay caducidad automática ni temporizador de advertencia. La habilitación online no admite retirada. «Mi perfil», edición propia de contacto y cambio propio de contraseña se retiraron por no corresponder a los flujos definidos; la recuperación funciona por correo.

## Correo

El registro de Paciente envía un enlace firmado de verificación. El inicio de sesión del paciente exige correo verificado. La recuperación utiliza un enlace temporal de un solo uso, mantiene contraseñas con hash y revoca las sesiones anteriores tras restablecerlas.

El entorno local usa `MAIL_MAILER=log`: registra el mensaje en el log privado para desarrollo y no lo entrega a una bandeja real. Para entrega real se configura el transporte SMTP mediante las variables de correo de Laravel en `.env`. Las pruebas simulan las notificaciones y no envían correos externos. No copiar enlaces de recuperación ni credenciales a los informes o al repositorio.

## Estado de los demás módulos

Las decisiones funcionales se toman de V6. La implementación económica de citas, retenciones, pago inmediato, WizPay, reembolsos, seguimiento y solicitudes de reprogramación aún no está completada. El código histórico de citas y reportes se conserva deshabilitado por defecto con `CLINIC_APPOINTMENTS_ENABLED=false`; habilitar ese indicador no implementa las reglas económicas de V6.

Este estado de implementación no representa nuevas preguntas de negocio ni autoriza funciones adicionales. El registro de adecuación indica el alcance comprobado de la entrega.

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
