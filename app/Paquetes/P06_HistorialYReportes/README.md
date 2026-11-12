# P06 — Historial y reportes administrativos

Referencia: `ofical.docx`, sección 5.1.2.

| CU | Responsabilidad | Implementación principal |
|---|---|---|
| CU15 | Consultar historial de citas | [AppointmentQueryController.php](../P05_CitasMedicas/Compartido/Http/AppointmentQueryController.php) — index, mode=history |
| CU16 | Generar reportes administrativos | [ReportController.php](CU16_GenerarReportesAdministrativos/Http/ReportController.php) — index |

## Código del paquete

- `CU16_GenerarReportesAdministrativos/Http/ReportController.php`

Los componentes de `Compartido` se usan desde varios CU. La matriz puede apuntar a otro paquete cuando la implementación aprovecha una consulta o transacción existente; no se duplica esa lógica.
