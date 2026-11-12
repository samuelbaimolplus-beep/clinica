# P01 — Gestión de usuarios y acceso

Referencia: `ofical.docx`, sección 5.1.2.

| CU | Responsabilidad | Implementación principal |
|---|---|---|
| CU01 | Iniciar sesión | [AuthController.php](CU01_IniciarSesion/Http/AuthController.php) — login / session |
| CU02 | Cerrar sesión | [LogoutController.php](CU02_CerrarSesion/Http/LogoutController.php) — logout |
| CU03 | Gestionar usuarios y roles | [UserController.php](CU03_GestionarUsuariosYRoles/Http/UserController.php) — index / show / save |
| CU23 | Registrar cuenta de paciente | [ManagedPatientAccountController.php](CU23_RegistrarCuentaDePaciente/Http/ManagedPatientAccountController.php) — register / store |

## Código del paquete

- `Compartido/Http/AccountActivationController.php`
- `Compartido/Http/AccountPasswordController.php`
- `Compartido/Http/AccountProfileController.php`
- `Compartido/Http/AccountRecoveryController.php`
- `Compartido/Http/AccountUsernameController.php`
- `Compartido/Servicios/EmailActivation.php`
- `Compartido/Servicios/SessionSecurity.php`
- `CU01_IniciarSesion/Http/AuthController.php`
- `CU02_CerrarSesion/Http/LogoutController.php`
- `CU03_GestionarUsuariosYRoles/Http/AccountManagementController.php`
- `CU03_GestionarUsuariosYRoles/Http/UserController.php`
- `CU03_GestionarUsuariosYRoles/Validacion/SaveUserRequest.php`
- `CU23_RegistrarCuentaDePaciente/Http/ManagedPatientAccountController.php`
- `CU23_RegistrarCuentaDePaciente/Http/PatientAccountsController.php`
- `CU23_RegistrarCuentaDePaciente/Servicios/CreatePatientAccount.php`
- `CU23_RegistrarCuentaDePaciente/Validacion/CreatePatientAccountRequest.php`
- `CU23_RegistrarCuentaDePaciente/Validacion/ManagedAccountRules.php`
- `CU23_RegistrarCuentaDePaciente/Validacion/RegisterManagedPatientRequest.php`

Los componentes de `Compartido` se usan desde varios CU. La matriz puede apuntar a otro paquete cuando la implementación aprovecha una consulta o transacción existente; no se duplica esa lógica.
