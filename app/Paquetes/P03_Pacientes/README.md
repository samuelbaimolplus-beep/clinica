# P03 — Gestión de pacientes

Referencia: `ofical.docx`, sección 5.1.2.

| CU | Responsabilidad | Implementación principal |
|---|---|---|
| CU06 | Gestionar pacientes | [PatientController.php](CU06_GestionarPacientes/Http/PatientController.php) — index / show / save |

## Código del paquete

- `CU06_GestionarPacientes/Http/PatientController.php`
- `CU06_GestionarPacientes/Validacion/SavePatientRequest.php`

Los componentes de `Compartido` se usan desde varios CU. La matriz puede apuntar a otro paquete cuando la implementación aprovecha una consulta o transacción existente; no se duplica esa lógica.
