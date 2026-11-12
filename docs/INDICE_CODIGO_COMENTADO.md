# Lectura del código comentado

Comentarios del código propio, en español. Se excluyen dependencias, compilados y archivos privados generados.

| Archivo | Responsabilidad |
|---|---|
| `app/Compartido/Catalogos/Http/ImpactController.php` | Entrada HTTP de ImpactController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Compartido/Catalogos/Http/StatusController.php` | Entrada HTTP de StatusController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Compartido/Catalogos/Servicios/CatalogImpact.php` | Calcula citas afectadas por cambios administrativos sin cancelarlas automáticamente. |
| `app/Compartido/Catalogos/Servicios/CatalogService.php` | Altas y modificaciones atómicas de catálogos, con roles, duplicados y conservación del historial. |
| `app/Compartido/Catalogos/Validacion/PersonRules.php` | Contrato de entrada de PersonRules. Rechaza valores inválidos antes de ejecutar reglas de negocio. |
| `app/Compartido/Http/ReferenceController.php` | Entrada HTTP de ReferenceController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Console/Commands/CreateClinicAdmin.php` | Componente CreateClinicAdmin: colabora con los módulos del sistema sin reemplazar sus controles del servidor. |
| `app/Console/Commands/CreateClinicSuperadmin.php` | Componente CreateClinicSuperadmin: colabora con los módulos del sistema sin reemplazar sus controles del servidor. |
| `app/Console/Commands/CreateDemoAccounts.php` | Prepara exclusivamente identidades ficticias locales y conserva contraseñas de demostración existentes. |
| `app/Console/Commands/PrepareClinicTests.php` | Prepara el esquema de la base exclusiva de pruebas, con comprobación del nombre antes de migrar. |
| `app/Database/SpanishPostgresBuilder.php` | Traduce introspección del esquema para que migraciones y modelos mantengan convenciones Laravel. |
| `app/Database/SpanishPostgresConnection.php` | Adapta identificadores SQL de Laravel al esquema español sin traducir valores ni literales. |
| `app/Http/Controllers/Controller.php` | Entrada HTTP de Controller. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Http/Middleware/ClinicalAccessAudit.php` | Entrada HTTP de ClinicalAccessAudit. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Http/Middleware/ClinicSession.php` | Entrada HTTP de ClinicSession. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Http/Middleware/PrivacyHeaders.php` | Evita caché de información privada y añade cabeceras de protección también a respuestas de error. |
| `app/Http/Middleware/ReceptionAgendaAccess.php` | Entrada HTTP de ReceptionAgendaAccess. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Http/Middleware/RequireAppointments.php` | Entrada HTTP de RequireAppointments. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Jobs/ReconcilePayPalPayment.php` | Componente ReconcilePayPalPayment: colabora con los módulos del sistema sin reemplazar sus controles del servidor. |
| `app/Models/AgendaAdjustment.php` | Entidad de bloqueos y aperturas de cupos. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. |
| `app/Models/Appointment.php` | Entidad de citas médicas. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. |
| `app/Models/AppointmentEvent.php` | Entidad de cambios de una cita. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. |
| `app/Models/AuditEvent.php` | Entidad de eventos inmutables de auditoría. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. |
| `app/Models/BookingRequest.php` | Entidad de retenciones temporales de reserva. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. |
| `app/Models/ClinicalSlot.php` | Entidad de identidades de franjas clínicas. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. |
| `app/Models/Doctor.php` | Entidad de profesionales médicos. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. |
| `app/Models/DoctorQuota.php` | Entidad de cupos por modalidad. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. |
| `app/Models/Patient.php` | Entidad de fichas de pacientes. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. |
| `app/Models/PatientAccountInvitation.php` | Entidad de vinculaciones administrativas heredadas. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. |
| `app/Models/Payment.php` | Entidad de pagos y confirmaciones. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. |
| `app/Models/RefundRequest.php` | Entidad de solicitudes de devolución. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. |
| `app/Models/RescheduleRequest.php` | Entidad de solicitudes de cambio. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. |
| `app/Models/Schedule.php` | Entidad de turnos semanales. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. |
| `app/Models/Specialty.php` | Entidad de especialidades y tarifas. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. |
| `app/Models/Unavailability.php` | Entidad de ausencias médicas. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. |
| `app/Models/User.php` | Entidad de cuentas de usuario. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. |
| `app/Notifications/VerifyClinicEmail.php` | Componente VerifyClinicEmail: colabora con los módulos del sistema sin reemplazar sus controles del servidor. |
| `app/Observers/ClinicAuditObserver.php` | Observa cambios del dominio y registra campos afectados sin copiar valores sensibles. |
| `app/Paquetes/P01_UsuariosYAcceso/Compartido/Http/AccountActivationController.php` | Entrada HTTP de AccountActivationController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P01_UsuariosYAcceso/Compartido/Http/AccountPasswordController.php` | Entrada HTTP de AccountPasswordController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P01_UsuariosYAcceso/Compartido/Http/AccountProfileController.php` | Entrada HTTP de AccountProfileController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P01_UsuariosYAcceso/Compartido/Http/AccountRecoveryController.php` | Entrada HTTP de AccountRecoveryController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P01_UsuariosYAcceso/Compartido/Http/AccountUsernameController.php` | Entrada HTTP de AccountUsernameController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P01_UsuariosYAcceso/Compartido/Servicios/AccountEligibility.php` | Reglas de elegibilidad de cuentas. Una ficha clínica puede existir sin cuenta y a cualquier edad. |
| `app/Paquetes/P01_UsuariosYAcceso/Compartido/Servicios/EmailActivation.php` | Códigos de correo de un solo uso: guarda HMAC, vencimiento e intentos, nunca el código en claro. |
| `app/Paquetes/P01_UsuariosYAcceso/Compartido/Servicios/SessionSecurity.php` | Revocación de sesiones cuando cambian credenciales, permisos, activación o elegibilidad del titular. |
| `app/Paquetes/P01_UsuariosYAcceso/CU01_IniciarSesion/Http/AuthController.php` | Inicio y consulta de sesión: comprueba credenciales, edad, consentimiento y activación antes de permitir acceso. |
| `app/Paquetes/P01_UsuariosYAcceso/CU02_CerrarSesion/Http/LogoutController.php` | Entrada HTTP de LogoutController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P01_UsuariosYAcceso/CU03_GestionarUsuariosYRoles/Http/AccountManagementController.php` | Entrada HTTP de AccountManagementController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P01_UsuariosYAcceso/CU03_GestionarUsuariosYRoles/Http/UserController.php` | Entrada HTTP de UserController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P01_UsuariosYAcceso/CU03_GestionarUsuariosYRoles/Validacion/SaveUserRequest.php` | Contrato de entrada de SaveUserRequest. Rechaza valores inválidos antes de ejecutar reglas de negocio. |
| `app/Paquetes/P01_UsuariosYAcceso/CU23_RegistrarCuentaDePaciente/Http/ManagedPatientAccountController.php` | Entrada HTTP de ManagedPatientAccountController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P01_UsuariosYAcceso/CU23_RegistrarCuentaDePaciente/Http/PatientAccountsController.php` | Entrada HTTP de PatientAccountsController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P01_UsuariosYAcceso/CU23_RegistrarCuentaDePaciente/Servicios/CreatePatientAccount.php` | Alta presencial y opcional del portal de un paciente adulto, con identidad y consentimiento verificados. |
| `app/Paquetes/P01_UsuariosYAcceso/CU23_RegistrarCuentaDePaciente/Validacion/CreatePatientAccountRequest.php` | Contrato de entrada de CreatePatientAccountRequest. Rechaza valores inválidos antes de ejecutar reglas de negocio. |
| `app/Paquetes/P01_UsuariosYAcceso/CU23_RegistrarCuentaDePaciente/Validacion/ManagedAccountRules.php` | Contrato de entrada de ManagedAccountRules. Rechaza valores inválidos antes de ejecutar reglas de negocio. |
| `app/Paquetes/P01_UsuariosYAcceso/CU23_RegistrarCuentaDePaciente/Validacion/RegisterManagedPatientRequest.php` | Contrato de entrada de RegisterManagedPatientRequest. Rechaza valores inválidos antes de ejecutar reglas de negocio. |
| `app/Paquetes/P02_PersonalMedicoYEspecialidades/CU04_GestionarMedicos/Http/DoctorController.php` | Entrada HTTP de DoctorController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P02_PersonalMedicoYEspecialidades/CU04_GestionarMedicos/Validacion/SaveDoctorRequest.php` | Contrato de entrada de SaveDoctorRequest. Rechaza valores inválidos antes de ejecutar reglas de negocio. |
| `app/Paquetes/P02_PersonalMedicoYEspecialidades/CU05_GestionarEspecialidades/Http/SpecialtyController.php` | Entrada HTTP de SpecialtyController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P02_PersonalMedicoYEspecialidades/CU05_GestionarEspecialidades/Validacion/SaveSpecialtyRequest.php` | Contrato de entrada de SaveSpecialtyRequest. Rechaza valores inválidos antes de ejecutar reglas de negocio. |
| `app/Paquetes/P03_Pacientes/CU06_GestionarPacientes/Http/PatientController.php` | Entrada HTTP de PatientController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P03_Pacientes/CU06_GestionarPacientes/Validacion/SavePatientRequest.php` | Contrato de entrada de SavePatientRequest. Rechaza valores inválidos antes de ejecutar reglas de negocio. |
| `app/Paquetes/P04_HorariosYDisponibilidad/Compartido/Servicios/AgendaAdjustments.php` | Propone, confirma y libera protección de demanda; distingue reservas online y bloqueos presenciales. |
| `app/Paquetes/P04_HorariosYDisponibilidad/Compartido/Servicios/ScheduleBlocks.php` | Calcula las franjas que forman un turno y aplica las indisponibilidades correspondientes. |
| `app/Paquetes/P04_HorariosYDisponibilidad/Compartido/Servicios/ScheduleImpact.php` | Localiza citas que necesitan intervención de Recepción tras cambiar disponibilidad o habilitaciones. |
| `app/Paquetes/P04_HorariosYDisponibilidad/Compartido/Servicios/ShiftModalities.php` | Distribuye franjas semanales entre modalidades, conservando la protección presencial inicial. |
| `app/Paquetes/P04_HorariosYDisponibilidad/CU07_GestionarHorariosMedicos/Http/ScheduleController.php` | Entrada HTTP de ScheduleController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P04_HorariosYDisponibilidad/CU07_GestionarHorariosMedicos/Servicios/DoctorQuotaService.php` | Guarda distribución de cupos y calcula capacidad efectiva según horarios, ausencias y ajustes. |
| `app/Paquetes/P04_HorariosYDisponibilidad/CU07_GestionarHorariosMedicos/Servicios/QuotaAllocator.php` | Reparte capacidad entera sin perder cupos por redondeos de porcentajes. |
| `app/Paquetes/P04_HorariosYDisponibilidad/CU07_GestionarHorariosMedicos/Servicios/ScheduleService.php` | Valida y guarda turnos sin superposición; las citas afectadas conservan su estado. |
| `app/Paquetes/P04_HorariosYDisponibilidad/CU07_GestionarHorariosMedicos/Validacion/SaveDoctorQuotaRequest.php` | Contrato de entrada de SaveDoctorQuotaRequest. Rechaza valores inválidos antes de ejecutar reglas de negocio. |
| `app/Paquetes/P04_HorariosYDisponibilidad/CU07_GestionarHorariosMedicos/Validacion/SaveScheduleRequest.php` | Contrato de entrada de SaveScheduleRequest. Rechaza valores inválidos antes de ejecutar reglas de negocio. |
| `app/Paquetes/P04_HorariosYDisponibilidad/CU07_GestionarHorariosMedicos/Validacion/SaveUnavailabilityRequest.php` | Contrato de entrada de SaveUnavailabilityRequest. Rechaza valores inválidos antes de ejecutar reglas de negocio. |
| `app/Paquetes/P04_HorariosYDisponibilidad/CU08_ConsultarDisponibilidadMedica/Http/AvailableSlotsController.php` | Entrada HTTP de AvailableSlotsController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P04_HorariosYDisponibilidad/CU08_ConsultarDisponibilidadMedica/Http/MedicalAvailabilityController.php` | Entrada HTTP de MedicalAvailabilityController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P04_HorariosYDisponibilidad/CU24_GestionarBloqueosProvisionales/Http/AgendaAdjustmentController.php` | Entrada HTTP de AgendaAdjustmentController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P04_HorariosYDisponibilidad/CU25_HabilitarCuposOnline/Http/ProtectedOnlineSlotsController.php` | Entrada HTTP de ProtectedOnlineSlotsController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P05_CitasMedicas/Compartido/Http/AppointmentActionController.php` | Autoriza transiciones de una cita; el servicio comprueba fecha, pago y estado antes de guardarlas. |
| `app/Paquetes/P05_CitasMedicas/Compartido/Http/AppointmentQueryController.php` | Consultas de agenda e historial con filtros de identidad y datos limitados para el médico. |
| `app/Paquetes/P05_CitasMedicas/Compartido/Http/WorkspaceController.php` | Resumen real de trabajo: filtra pacientes y médicos por identidad y muestra tareas pendientes por rol. |
| `app/Paquetes/P05_CitasMedicas/Compartido/Servicios/AppointmentService.php` | Coordinación de citas, franjas, pagos y estados dentro de transacciones con bloqueos contra concurrencia. |
| `app/Paquetes/P05_CitasMedicas/CU09_ProgramarCitaMedica/Http/AppointmentController.php` | Entrada HTTP de AppointmentController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P05_CitasMedicas/CU10_ReprogramarCitaMedica/Http/ReceptionInboxController.php` | Entrada HTTP de ReceptionInboxController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P05_CitasMedicas/CU10_ReprogramarCitaMedica/Http/ReprogrammingController.php` | Solicitudes y acuerdos de reprogramación; conserva la cita vigente hasta resolver el cambio. |
| `app/Paquetes/P05_CitasMedicas/CU17_ConsultarCitasAfectadas/Http/ScheduleImpactController.php` | Entrada HTTP de ScheduleImpactController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P06_HistorialYReportes/CU16_GenerarReportesAdministrativos/Http/ReportController.php` | Genera reportes autorizados, porcentajes y exportación CSV con datos del servidor. |
| `app/Paquetes/P07_ReservasPagosYSeguimientos/Compartido/Integraciones/PayPalGateway.php` | Adaptador exclusivo de PayPal Sandbox. Verifica identidad, moneda, importe y captura de cada orden. |
| `app/Paquetes/P07_ReservasPagosYSeguimientos/Compartido/Integraciones/PayPalWebhookController.php` | Receptor de notificaciones PayPal: valida autenticidad y concilia antes de modificar pagos. |
| `app/Paquetes/P07_ReservasPagosYSeguimientos/Compartido/Servicios/RefundService.php` | Separa solicitud, política de admisión, decisión administrativa y registro de entrega del reembolso. |
| `app/Paquetes/P07_ReservasPagosYSeguimientos/CU18_ReservarCitaOnline/Http/OnlineBookingController.php` | Entrada HTTP de OnlineBookingController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P07_ReservasPagosYSeguimientos/CU18_ReservarCitaOnline/Servicios/OnlineBookingService.php` | Retención online, intentos de pago y conciliación; solo una confirmación del servidor emite la cita. |
| `app/Paquetes/P07_ReservasPagosYSeguimientos/CU19_VerificarPagoOExencion/Http/PaymentsController.php` | Entrada HTTP de PaymentsController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P07_ReservasPagosYSeguimientos/CU19_VerificarPagoOExencion/Servicios/PaymentVerifier.php` | Verifica tarifa y evidencia del cobro presencial antes de permitir emitir una ficha o completar el ingreso. |
| `app/Paquetes/P07_ReservasPagosYSeguimientos/CU20_SolicitarReembolso/Http/RefundRequestController.php` | Entrada HTTP de RefundRequestController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P07_ReservasPagosYSeguimientos/CU21_RegistrarCitaDeSeguimiento/Servicios/FollowupPolicy.php` | Determina si un seguimiento conserva su gratuidad según origen, fecha sugerida y causa del cambio. |
| `app/Paquetes/P07_ReservasPagosYSeguimientos/CU26_ResolverSolicitudDeReembolso/Http/RefundResolutionController.php` | Entrada HTTP de RefundResolutionController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P08_Auditoria/CU22_ConsultarBitacoraDeAuditoria/Http/AuditController.php` | Entrada HTTP de AuditController. Coordina la solicitud y delega las reglas del dominio en sus servicios. |
| `app/Paquetes/P08_Auditoria/CU22_ConsultarBitacoraDeAuditoria/Servicios/AuditLogger.php` | Registro de auditoría con metadatos permitidos e IP del cliente; no almacena contraseñas ni cuerpos HTTP. |
| `app/Providers/AppServiceProvider.php` | Registra adaptación PostgreSQL, permisos, observadores y vínculos de recuperación de Laravel. |
| `app/Support/ClinicAccess.php` | Matriz central de vistas por rol; Superadmin hereda gestión, los demás roles mantienen su ámbito. |
| `app/Support/SpecialtyName.php` | Normaliza nombres de especialidad para comparar duplicados sin diferencias de espacios o mayúsculas. |
| `app/Support/Username.php` | Normalización, generación y unicidad de identificadores de acceso independientes del correo. |
| `bootstrap/app.php` | Inicialización de Laravel: configura dependencias, rutas, middleware o respuestas de la aplicación. |
| `bootstrap/providers.php` | Inicialización de Laravel: configura dependencias, rutas, middleware o respuestas de la aplicación. |
| `config/app.php` | Configuración de app. Los valores de entorno se leen desde Laravel; no incluya credenciales reales en este archivo. |
| `config/auth.php` | Configuración de auth. Los valores de entorno se leen desde Laravel; no incluya credenciales reales en este archivo. |
| `config/cache.php` | Configuración de cache. Los valores de entorno se leen desde Laravel; no incluya credenciales reales en este archivo. |
| `config/clinic.php` | Configuración de clinic. Los valores de entorno se leen desde Laravel; no incluya credenciales reales en este archivo. |
| `config/database.php` | Configuración de database. Los valores de entorno se leen desde Laravel; no incluya credenciales reales en este archivo. |
| `config/database_names.php` | Configuración de database_names. Los valores de entorno se leen desde Laravel; no incluya credenciales reales en este archivo. |
| `config/database_object_names.php` | Configuración de database_object_names. Los valores de entorno se leen desde Laravel; no incluya credenciales reales en este archivo. |
| `config/filesystems.php` | Configuración de filesystems. Los valores de entorno se leen desde Laravel; no incluya credenciales reales en este archivo. |
| `config/logging.php` | Configuración de logging. Los valores de entorno se leen desde Laravel; no incluya credenciales reales en este archivo. |
| `config/mail.php` | Configuración de mail. Los valores de entorno se leen desde Laravel; no incluya credenciales reales en este archivo. |
| `config/paypal.php` | Configuración de paypal. Los valores de entorno se leen desde Laravel; no incluya credenciales reales en este archivo. |
| `config/queue.php` | Configuración de queue. Los valores de entorno se leen desde Laravel; no incluya credenciales reales en este archivo. |
| `config/services.php` | Configuración de services. Los valores de entorno se leen desde Laravel; no incluya credenciales reales en este archivo. |
| `config/session.php` | Configuración de session. Los valores de entorno se leen desde Laravel; no incluya credenciales reales en este archivo. |
| `database/factories/UserFactory.php` | Datos ficticios para desarrollo o pruebas; no representan personas reales ni sustituyen el alta operativa. |
| `database/migrations/0001_01_01_000000_create_users_table.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/0001_01_01_000001_create_cache_table.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/0001_01_01_000002_create_jobs_table.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/2026_10_24_000001_create_clinic_tables.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/2026_11_02_000001_add_patient_accounts_and_audit.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/2026_11_02_000002_create_patient_account_invitations.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/2026_11_02_000003_prevent_exact_undocumented_patient_duplicates.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/2026_11_04_000001_normalize_specialty_name_uniqueness.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/2026_11_04_000002_create_doctor_quotas.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/2026_11_07_000001_allow_multiple_medical_shifts.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/2026_11_07_000002_require_whole_quota_percentages.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/2026_11_07_000003_create_agenda_adjustments.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/2026_11_07_000004_align_schedules_and_demand_with_v6.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/2026_11_07_000010_complete_clinical_workflows.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/2026_11_07_000011_add_payment_reconciliation_backoff.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/2026_11_07_000012_persist_clinical_slot_identity.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/2026_11_07_000020_add_superadmin_role.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/2026_11_07_000021_allow_cancelling_unpaid_booking_requests.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/2026_11_07_000022_add_usernames_to_users.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/2026_11_07_000030_translate_database_identifiers_to_spanish.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/2026_11_07_000040_add_clinic_triggers_and_procedures.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/2026_11_07_000050_add_paypal_sandbox_payment_details.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/2026_11_08_000060_add_account_birth_date.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/migrations/2026_11_08_000070_add_audit_client_ip.php` | Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. |
| `database/seeders/DatabaseSeeder.php` | Datos ficticios para desarrollo o pruebas; no representan personas reales ni sustituyen el alta operativa. |
| `database/seeders/DemoDataSeeder.php` | Datos ficticios para desarrollo o pruebas; no representan personas reales ni sustituyen el alta operativa. |
| `resources/css/app.css` | Componente app: colabora con los módulos del sistema sin reemplazar sus controles del servidor. |
| `resources/js/app.js` | Arranque de la interfaz: sesión, navegación por rol, formularios, diálogos y limpieza de información privada. |
| `resources/js/lib/api.js` | Cliente HTTP: CSRF, sesión y errores. Nunca reenvía automáticamente una escritura fallida. |
| `resources/js/lib/clinical-flow.js` | Reglas puras de presentación de estados. El servidor conserva la decisión final sobre cada transición. |
| `resources/js/lib/icons.js` | Iconos internos usados para orientar tareas y navegación; no representan permisos. |
| `resources/js/lib/navigation.js` | Menú y rutas legibles derivados de los permisos recibidos del servidor. |
| `resources/js/lib/password-visibility.js` | Alterna visibilidad de una contraseña únicamente por acción expresa de su titular. |
| `resources/js/lib/schedule-planner.js` | Cálculo visual de huecos y cruces; los turnos consecutivos no se superponen. |
| `resources/js/modules/accounts.js` | Administración de cuentas y contraseñas por personal autorizado, sin mostrar claves existentes. |
| `resources/js/modules/agenda-adjustments.js` | CU24/CU25: consultar y proponer no modifica cupos; confirmar sí aplica el ajuste autorizado. |
| `resources/js/modules/appointment-workspace.js` | CU10–CU15: tarjetas de citas con acciones por rol y estado. Ocultar un botón no sustituye la autorización del backend. |
| `resources/js/modules/audit.js` | CU22: consulta paginada e inmutable de eventos, actor, dirección IP y metadatos permitidos. |
| `resources/js/modules/booking-workflow.js` | CU08/CU09/CU18/CU21: selección guiada de atención, cupo y confirmación, separada del registro definitivo en el servidor. |
| `resources/js/modules/catalogs.js` | CU03–CU06: directorios y altas en contexto. La ficha de paciente es independiente de una cuenta opcional. |
| `resources/js/modules/clinical.js` | Acciones compartidas: ficha, historial, cambio de horario, asistencia, consulta y conciliación de PayPal. |
| `resources/js/modules/contextual-tools.js` | Presenta reportes, auditoría y ajustes de cupos mediante decisiones contextuales antes de pedir criterios. |
| `resources/js/modules/finance-workspace.js` | CU20/CU26: solicitud, decisión y entrega externa de devoluciones, cada una con confirmación y estado propios. |
| `resources/js/modules/impacts.js` | CU17: citas afectadas conservadas, con acciones de Recepción para contactar y resolver. |
| `resources/js/modules/patients.js` | Activación y recuperación de cuentas creadas presencialmente; no ofrece autorregistro. |
| `resources/js/modules/reception-inbox.js` | Avisos periódicos de nuevas solicitudes sin duplicar las operaciones de resolución. |
| `resources/js/modules/schedules.js` | CU07: semana del médico, conflictos de turnos, ausencias y distribución de cupos. |
| `resources/js/modules/session.js` | Gestión personal de usuario, contraseña y contacto; exige confirmar la contraseña actual. |
| `resources/js/modules/supervision.js` | Consultas globales de pagos, exenciones y cuentas para Superadmin. |
| `resources/js/modules/workspace.js` | Inicio por rol con datos reales y accesos al siguiente paso de la atención. |
| `resources/views/auth/account-result.blade.php` | Vista de account-result. Blade escapa datos; la interfaz usa los endpoints autorizados de Laravel. |
| `resources/views/auth/reset-password.blade.php` | Vista de reset-password. Blade escapa datos; la interfaz usa los endpoints autorizados de Laravel. |
| `resources/views/clinic.blade.php` | Vista de clinic. Blade escapa datos; la interfaz usa los endpoints autorizados de Laravel. |
| `resources/views/terms.blade.php` | Vista de terms. Blade escapa datos; la interfaz usa los endpoints autorizados de Laravel. |
| `resources/views/welcome.blade.php` | Vista de welcome. Blade escapa datos; la interfaz usa los endpoints autorizados de Laravel. |
| `routes/console.php` | Puntos de entrada de console: agrupan operaciones y sus controles de acceso antes de llegar al dominio. |
| `routes/web.php` | Puntos de entrada de web: agrupan operaciones y sus controles de acceso antes de llegar al dominio. |
