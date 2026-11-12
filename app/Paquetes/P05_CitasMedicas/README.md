# P05 — Gestión y seguimiento de citas médicas

Referencia: `ofical.docx`, sección 5.1.2.

| CU | Responsabilidad | Implementación principal |
|---|---|---|
| CU09 | Programar cita médica | [AppointmentController.php](CU09_ProgramarCitaMedica/Http/AppointmentController.php) — store |
| CU10 | Reprogramar cita médica | [ReprogrammingController.php](CU10_ReprogramarCitaMedica/Http/ReprogrammingController.php) — reschedule / requestReschedule / rejectReschedule |
| CU11 | Cancelar cita médica | [AppointmentActionController.php](Compartido/Http/AppointmentActionController.php) — action → transition(cancel) |
| CU12 | Consultar agenda médica | [AppointmentQueryController.php](Compartido/Http/AppointmentQueryController.php) — index, mode=agenda |
| CU13 | Registrar asistencia e inasistencia | [AppointmentActionController.php](Compartido/Http/AppointmentActionController.php) — action → transition(attendance / absent) |
| CU14 | Registrar cita como atendida | [AppointmentActionController.php](Compartido/Http/AppointmentActionController.php) — action → transition(attend / missed-call) |
| CU17 | Consultar citas afectadas | [ScheduleImpactController.php](CU17_ConsultarCitasAfectadas/Http/ScheduleImpactController.php) — __invoke |

## Código del paquete

- `Compartido/Http/AppointmentActionController.php`
- `Compartido/Http/AppointmentQueryController.php`
- `Compartido/Servicios/AppointmentService.php`
- `CU09_ProgramarCitaMedica/Http/AppointmentController.php`
- `CU10_ReprogramarCitaMedica/Http/ReceptionInboxController.php`
- `CU10_ReprogramarCitaMedica/Http/ReprogrammingController.php`
- `CU17_ConsultarCitasAfectadas/Http/ScheduleImpactController.php`

Los componentes de `Compartido` se usan desde varios CU. La matriz puede apuntar a otro paquete cuando la implementación aprovecha una consulta o transacción existente; no se duplica esa lógica.
