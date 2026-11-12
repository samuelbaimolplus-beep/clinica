# P04 — Gestión de horarios y disponibilidad médica

Referencia: `ofical.docx`, sección 5.1.2.

| CU | Responsabilidad | Implementación principal |
|---|---|---|
| CU07 | Gestionar horarios médicos | [ScheduleController.php](CU07_GestionarHorariosMedicos/Http/ScheduleController.php) — index / save / unavailable / quota / capacity |
| CU08 | Consultar disponibilidad médica | [MedicalAvailabilityController.php](CU08_ConsultarDisponibilidadMedica/Http/MedicalAvailabilityController.php) — options / index |
| CU24 | Gestionar bloqueos provisionales por demanda presencial | [AgendaAdjustmentController.php](CU24_GestionarBloqueosProvisionales/Http/AgendaAdjustmentController.php) — propose / store / release / releaseSelected |
| CU25 | Habilitar anticipadamente cupos online | [ProtectedOnlineSlotsController.php](CU25_HabilitarCuposOnline/Http/ProtectedOnlineSlotsController.php) — protectedBlocks |

## Código del paquete

- `Compartido/Servicios/AgendaAdjustments.php`
- `Compartido/Servicios/ScheduleBlocks.php`
- `Compartido/Servicios/ScheduleImpact.php`
- `Compartido/Servicios/ShiftModalities.php`
- `CU07_GestionarHorariosMedicos/Http/ScheduleController.php`
- `CU07_GestionarHorariosMedicos/Servicios/DoctorQuotaService.php`
- `CU07_GestionarHorariosMedicos/Servicios/QuotaAllocator.php`
- `CU07_GestionarHorariosMedicos/Servicios/ScheduleService.php`
- `CU07_GestionarHorariosMedicos/Validacion/SaveDoctorQuotaRequest.php`
- `CU07_GestionarHorariosMedicos/Validacion/SaveScheduleRequest.php`
- `CU07_GestionarHorariosMedicos/Validacion/SaveUnavailabilityRequest.php`
- `CU08_ConsultarDisponibilidadMedica/Http/AvailableSlotsController.php`
- `CU08_ConsultarDisponibilidadMedica/Http/MedicalAvailabilityController.php`
- `CU24_GestionarBloqueosProvisionales/Http/AgendaAdjustmentController.php`
- `CU25_HabilitarCuposOnline/Http/ProtectedOnlineSlotsController.php`

Los componentes de `Compartido` se usan desde varios CU. La matriz puede apuntar a otro paquete cuando la implementación aprovecha una consulta o transacción existente; no se duplica esa lógica.
