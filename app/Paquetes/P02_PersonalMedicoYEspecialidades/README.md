# P02 — Gestión de personal médico y especialidades

Referencia: `ofical.docx`, sección 5.1.2.

| CU | Responsabilidad | Implementación principal |
|---|---|---|
| CU04 | Gestionar médicos | [DoctorController.php](CU04_GestionarMedicos/Http/DoctorController.php) — index / show / save |
| CU05 | Gestionar especialidades | [SpecialtyController.php](CU05_GestionarEspecialidades/Http/SpecialtyController.php) — index / show / save |

## Código del paquete

- `CU04_GestionarMedicos/Http/DoctorController.php`
- `CU04_GestionarMedicos/Validacion/SaveDoctorRequest.php`
- `CU05_GestionarEspecialidades/Http/SpecialtyController.php`
- `CU05_GestionarEspecialidades/Validacion/SaveSpecialtyRequest.php`

Los componentes de `Compartido` se usan desde varios CU. La matriz puede apuntar a otro paquete cuando la implementación aprovecha una consulta o transacción existente; no se duplica esa lógica.
