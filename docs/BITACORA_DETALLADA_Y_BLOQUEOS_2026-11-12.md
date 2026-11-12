# Bitácora detallada y revisión de bloqueos

## Cambios en la bitácora

Antes se conservaban principalmente los nombres de los campos modificados. Para eventos nuevos se registra:

- Responsable: código, nombre, usuario y rol vigentes al realizar la acción; fecha, IP y operación HTTP sin cuerpos, parámetros de consulta ni credenciales.
- Identificación del registro y copia de sus datos de negocio en ese momento, con nombres de médico, paciente y especialidad cuando corresponda.
- Valores anteriores y nuevos de cada campo permitido en altas, modificaciones y eliminaciones. Las altas incluyen los valores predeterminados y las normalizaciones de PostgreSQL.
- Especialidades añadidas o retiradas de un médico, conservando códigos y nombres de ambas listas.
- Motivo, causa y aceptación del paciente en la reprogramación, además del horario anterior y nuevo. Las acciones de las citas tienen eventos identificables de programación, reprogramación, cancelación, asistencia y atención.
- Identificador intentado al fallar el acceso. Los cambios de credencial se señalan sin guardar el valor anterior, el nuevo ni sus hashes.

La pantalla traduce campos y operaciones y presenta una tabla Campo / Antes / Después, el registro y su contexto. El listado incluye un resumen; los valores completos se recuperan al consultar un evento. Los importes se muestran en unidades monetarias, indicando BOB o la moneda del proveedor.

La migración `2026_11_12_000080_expand_audit_event_details` amplía el procedimiento SQL y filtra secretos también dentro de objetos y listas. La bitácora sigue siendo inmutable y consultable solo por los roles administrativos autorizados. Los eventos históricos no se reescriben: la pantalla explica cuando no existen valores anteriores y nuevos.

## Bloqueos provisionales

Ruta: Recepción → Bloqueos provisionales (`#bloqueos`). Recepcionista y Superadmin pueden proponer horarios de hoy, seleccionar los propuestos y confirmar. Proponer por sí solo no crea bloqueos.

Los bloqueos confirmados aparecen en la jornada; se conservan al recargar. Pueden liberarse mediante selección y confirmación. Un bloqueo compatible protege el cupo presencial de la especialidad y no se ofrece al paciente en línea ni a otra especialidad. Se consume cuando corresponde a una cita presencial real con pago o exención validado.

Las pruebas cubren propuesta, selección parcial, persistencia visible, liberación, permisos y disponibilidad por especialidad/modalidad. Se ejecutan con PostgreSQL aislado y una interfaz con API simulada; no crean citas, bloqueos ni pagos en la base operativa.

## Accesos de demostración

Se verificaron localmente `demo.medico2` (Médico) y `demo.recepcion2` (Recepcionista). Daniel Demostración tiene Medicina General, Pediatría y Ginecología, con turnos de 08:00 a 12:00 y de 16:00 a 22:00. Recepción no tiene médico, especialidades ni turnos asociados.

Se creó y verificó localmente `demo.admin`, con rol Administrador, estado activo y cuenta activada. Tiene las funciones administrativas, incluida la bitácora, sin permisos de gestión de Superadmin. Las contraseñas nuevas se entregan directamente al usuario; no se guardan en este informe ni en archivos de credenciales.

La migración se aplicó en la base local operativa, en la base aislada de pruebas y en Neon, sin borrar registros. El Administrador de demostración está incorporado en local y Neon. La publicación se limita al repositorio autorizado de la clínica.
