# P08 — Auditoría del sistema

Referencia: `ofical.docx`, sección 5.1.2.

| CU | Responsabilidad | Implementación principal |
|---|---|---|
| CU22 | Consultar bitácora de auditoría | [AuditController.php](CU22_ConsultarBitacoraDeAuditoria/Http/AuditController.php) — filters / index / show |

## Código del paquete

- `CU22_ConsultarBitacoraDeAuditoria/Http/AuditController.php`
- `CU22_ConsultarBitacoraDeAuditoria/Servicios/AuditLogger.php`

Los componentes de `Compartido` se usan desde varios CU. La matriz puede apuntar a otro paquete cuando la implementación aprovecha una consulta o transacción existente; no se duplica esa lógica.
