# Ausencias médicas y cuentas de demostración

## Registro y retiro

Administrador y Superadmin gestionan las ausencias desde Horarios médicos → Ausencias y vacaciones. Cada ausencia activa ofrece Ver citas afectadas y Retirar ausencia. Para retirarla se exige un motivo y una confirmación; se guardan quién la retiró, cuándo y por qué. El registro permanece visible como Retirada y la bitácora conserva sus cambios.

Retirar una ausencia permite atender según los turnos y las demás restricciones vigentes. No cambia el estado ni la programación de las citas, no libera cupos ocupados y no retira otras ausencias. También se permite retirar la ausencia de un médico inactivo, sin activarlo por ese hecho.

## Evaluación de citas afectadas

El filtro anterior exigía que el inicio de la cita fuera posterior a la hora actual. Omitía citas todavía pendientes, incluso al consultar explícitamente una ausencia que se superponía con ellas.

La vista previa, el registro y la consulta posterior usan ahora el mismo cálculo: mismo médico, cita Programada o Confirmada e intersección real de los intervalos. Incluye las pendientes cuya hora o fecha ya pasó. No cuenta Atendida, Cancelada ni Inasistencia. Una cita que termina exactamente al comenzar la ausencia, o empieza exactamente al terminarla, no se considera superpuesta.

Al guardar se muestra el resultado con código, paciente, especialidad, fecha, horario y estado. Las consultas de citas afectadas y la disponibilidad ignoran las ausencias retiradas. Los cambios de turnos solo generan conflictos de configuración para fechas actuales o futuras; los conflictos por una ausencia explícita incluyen también las citas históricas pendientes.

## Contraseñas y correos de demostración

En Usuarios y roles → Consultar todas las cuentas → Cambiar contraseña, Superadmin puede asignar contraseñas de al menos 6 caracteres sin requisitos de composición. Se mantiene la confirmación, el almacenamiento mediante hash, la revocación de sesiones anteriores y la protección de la bitácora. Los demás flujos de contraseñas conservan sus reglas.

`php artisan clinic:confirm-demo-emails` confirma exclusivamente cuentas ficticias conocidas, comprobando usuario, correo reservado y rol. Completa la fecha de nacimiento únicamente si falta en personal ficticio antiguo, usando una fecha adulta de demostración. Es idempotente, no cambia contraseñas, no envía correos y no activa cuentas personales. El seeder también confirma las cuentas ficticias nuevas que genera.

Las cuentas preferidas para demostración son `demo.admin`, `demo.medico`, `demo.medico2`, `demo.recepcion`, `demo.recepcion2`, `demo.paciente1` y `demo.paciente2`. Los accesos heredados `recepcion` y `medico1` a `medico5` se conservan. Las contraseñas se entregan al usuario y no se incluyen en este documento ni en el repositorio.

## Validación

- Servidor: 214 pruebas aprobadas, 2358 aserciones, con PostgreSQL aislado.
- JavaScript: 19 pruebas aprobadas y compilación Vite correcta.
- Interfaz: 5 pruebas aprobadas de bitácora, registro/retiro con ambos roles y cambio de contraseña sencilla. La API se simula en estas pruebas; los permisos y la persistencia se verifican en las pruebas de servidor.
- Base local: migraciones aplicadas, cuentas ficticias confirmadas y detección de una cita pendiente en una ausencia existente que antes se excluía por fecha.
- Neon: respaldo privado previo; migraciones de bitácora y retiro aplicadas; Administrador de demostración incorporado y correos ficticios confirmados sin sobrescribir las contraseñas existentes.
