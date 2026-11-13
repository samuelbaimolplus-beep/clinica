<?php

/** Puntos de entrada de web: agrupan operaciones y sus controles de acceso antes de llegar al dominio. */

use App\Compartido\Catalogos\Http\ImpactController;
use App\Compartido\Catalogos\Http\StatusController;
use App\Compartido\Http\ReferenceController;
use App\Http\Middleware\ClinicalAccessAudit;
use App\Http\Middleware\ClinicSession;
use App\Http\Middleware\ReceptionAgendaAccess;
use App\Http\Middleware\RequireAppointments;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Http\AccountActivationController;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Http\AccountPasswordController;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Http\AccountProfileController;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Http\AccountRecoveryController;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Http\AccountUsernameController;
use App\Paquetes\P01_UsuariosYAcceso\CU01_IniciarSesion\Http\AuthController;
use App\Paquetes\P01_UsuariosYAcceso\CU02_CerrarSesion\Http\LogoutController;
use App\Paquetes\P01_UsuariosYAcceso\CU03_GestionarUsuariosYRoles\Http\AccountManagementController;
use App\Paquetes\P01_UsuariosYAcceso\CU03_GestionarUsuariosYRoles\Http\UserController;
use App\Paquetes\P01_UsuariosYAcceso\CU23_RegistrarCuentaDePaciente\Http\ManagedPatientAccountController;
use App\Paquetes\P01_UsuariosYAcceso\CU23_RegistrarCuentaDePaciente\Http\PatientAccountsController;
use App\Paquetes\P02_PersonalMedicoYEspecialidades\CU04_GestionarMedicos\Http\DoctorController;
use App\Paquetes\P02_PersonalMedicoYEspecialidades\CU05_GestionarEspecialidades\Http\SpecialtyController;
use App\Paquetes\P03_Pacientes\CU06_GestionarPacientes\Http\PatientController;
use App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Http\ScheduleController;
use App\Paquetes\P04_HorariosYDisponibilidad\CU08_ConsultarDisponibilidadMedica\Http\AvailableSlotsController;
use App\Paquetes\P04_HorariosYDisponibilidad\CU08_ConsultarDisponibilidadMedica\Http\MedicalAvailabilityController;
use App\Paquetes\P04_HorariosYDisponibilidad\CU24_GestionarBloqueosProvisionales\Http\AgendaAdjustmentController;
use App\Paquetes\P04_HorariosYDisponibilidad\CU25_HabilitarCuposOnline\Http\ProtectedOnlineSlotsController;
use App\Paquetes\P05_CitasMedicas\Compartido\Http\AppointmentActionController;
use App\Paquetes\P05_CitasMedicas\Compartido\Http\AppointmentQueryController;
use App\Paquetes\P05_CitasMedicas\Compartido\Http\WorkspaceController;
use App\Paquetes\P05_CitasMedicas\CU09_ProgramarCitaMedica\Http\AppointmentController;
use App\Paquetes\P05_CitasMedicas\CU10_ReprogramarCitaMedica\Http\ReceptionInboxController;
use App\Paquetes\P05_CitasMedicas\CU10_ReprogramarCitaMedica\Http\ReprogrammingController;
use App\Paquetes\P05_CitasMedicas\CU17_ConsultarCitasAfectadas\Http\ScheduleImpactController;
use App\Paquetes\P06_HistorialYReportes\CU16_GenerarReportesAdministrativos\Http\ReportController;
use App\Paquetes\P07_ReservasPagosYSeguimientos\Compartido\Integraciones\PayPalWebhookController;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU18_ReservarCitaOnline\Http\OnlineBookingController;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU19_VerificarPagoOExencion\Http\PaymentsController;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU20_SolicitarReembolso\Http\RefundRequestController;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU26_ResolverSolicitudDeReembolso\Http\RefundResolutionController;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Http\AuditController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('clinic');
});
Route::view('/terms', 'terms')->name('terms');
Route::post('/pagos/paypal/webhook', PayPalWebhookController::class)->middleware('throttle:60,1');
// El retorno del navegador no confirma pagos; solo vuelve a la reserva del usuario.
Route::get('/pagos/paypal/{result}', function (Request $request) {
    $id = filter_var($request->query('operacion'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return redirect('/'.($id ? '?paypal_booking='.$id : '').'#reservar-cita');
})->whereIn('result', ['retorno', 'cancelado']);
Route::get('/password/reset/{token}', fn (string $token) => view('auth.reset-password', ['token' => $token]));

Route::prefix('api')->group(function () {
    Route::get('session', [AuthController::class, 'consultarSesion']);
    Route::post('login', [AuthController::class, 'iniciarSesion'])->middleware('throttle:6,1');
    Route::post('account/email', [AccountRecoveryController::class, 'enviar'])->middleware('throttle:6,1');
    Route::post('account/activate', AccountActivationController::class)->middleware('throttle:6,1');
    Route::post('account/reset', [AccountRecoveryController::class, 'restablecer'])->middleware('throttle:6,1');
    Route::post('logout', [LogoutController::class, 'cerrarSesion']);
    Route::middleware(ClinicSession::class)->group(function () {
        Route::patch('account/username', AccountUsernameController::class)->middleware('throttle:6,1');
        Route::patch('account/password', AccountPasswordController::class)->middleware('throttle:6,1');
        Route::get('account/profile', [AccountProfileController::class, 'consultarDetalle']);
        Route::patch('account/profile', [AccountProfileController::class, 'actualizar'])->middleware('throttle:6,1');
        Route::get('supervision/accounts', [AccountManagementController::class, 'listar']);
        Route::post('supervision/accounts/{id}/password', [AccountManagementController::class, 'cambiarContrasena'])->whereNumber('id')->middleware('throttle:6,1');
        Route::get('references', ReferenceController::class);
        Route::get('catalog-impacts', ImpactController::class);
        Route::get('schedule-impacts', ScheduleImpactController::class);
        Route::get('medical-availability/options', [MedicalAvailabilityController::class, 'opciones']);
        Route::get('medical-availability', [MedicalAvailabilityController::class, 'listar']);
        Route::middleware(ReceptionAgendaAccess::class)->group(function () {
            Route::get('agenda-adjustments', [AgendaAdjustmentController::class, 'listar']);
            Route::post('agenda-adjustments/proposal', [AgendaAdjustmentController::class, 'proponer']);
            Route::get('agenda-adjustments/protected', [ProtectedOnlineSlotsController::class, 'franjasProtegidas']);
            Route::post('agenda-adjustments', [AgendaAdjustmentController::class, 'registrar']);
            Route::patch('agenda-adjustments/{id}/release', [AgendaAdjustmentController::class, 'liberar'])->whereNumber('id');
            Route::post('agenda-adjustments/release', [AgendaAdjustmentController::class, 'liberarSeleccionados']);
        });
        Route::middleware('can:view-audit')->group(function () {
            Route::get('audit/filters', [AuditController::class, 'filtros']);
            Route::get('audit', [AuditController::class, 'listar']);
            Route::get('audit/{id}', [AuditController::class, 'consultarDetalle'])->whereNumber('id');
        });

        // CU03–CU06: contratos explícitos por módulo y autorización antes de consultar datos.
        Route::middleware('can:manage-users')->group(function () {
            Route::get('entities/users/{id}', [UserController::class, 'consultarDetalle'])->whereNumber('id');
            Route::patch('entities/users/{id}/status', StatusController::class)->whereNumber('id')->defaults('catalog', 'users');
            Route::get('entities/users', [UserController::class, 'listar']);
            Route::post('entities/users', [UserController::class, 'guardar']);
            Route::put('entities/users/{id}', [UserController::class, 'guardar'])->whereNumber('id');
        });
        Route::middleware('can:manage-doctors')->group(function () {
            Route::get('entities/doctors/{id}', [DoctorController::class, 'consultarDetalle'])->whereNumber('id');
            Route::patch('entities/doctors/{id}/status', StatusController::class)->whereNumber('id')->defaults('catalog', 'doctors');
            Route::get('entities/doctors', [DoctorController::class, 'listar']);
            Route::post('entities/doctors', [DoctorController::class, 'guardar']);
            Route::put('entities/doctors/{id}', [DoctorController::class, 'guardar'])->whereNumber('id');
        });
        Route::middleware('can:manage-specialties')->group(function () {
            Route::get('entities/specialties/{id}', [SpecialtyController::class, 'consultarDetalle'])->whereNumber('id');
            Route::patch('entities/specialties/{id}/status', StatusController::class)->whereNumber('id')->defaults('catalog', 'specialties');
            Route::get('entities/specialties', [SpecialtyController::class, 'listar']);
            Route::post('entities/specialties', [SpecialtyController::class, 'guardar']);
            Route::put('entities/specialties/{id}', [SpecialtyController::class, 'guardar'])->whereNumber('id');
        });
        Route::middleware('can:manage-patients')->group(function () {
            Route::get('entities/patients/{id}', [PatientController::class, 'consultarDetalle'])->whereNumber('id');
            Route::patch('entities/patients/{id}/status', StatusController::class)->whereNumber('id')->defaults('catalog', 'patients');
            Route::get('entities/patients', [PatientController::class, 'listar']);
            Route::post('entities/patients', [PatientController::class, 'guardar']);
            Route::put('entities/patients/{id}', [PatientController::class, 'guardar'])->whereNumber('id');
        });
        Route::middleware('can:manage-patient-accounts')->group(function () {
            Route::post('patients/with-account', [ManagedPatientAccountController::class, 'registrar']);
            Route::post('patients/{id}/account', [ManagedPatientAccountController::class, 'crearCuentaParaPaciente'])->whereNumber('id');
        });
        Route::middleware('can:manage-schedules')->group(function () {
            Route::put('doctor-quotas', [ScheduleController::class, 'guardarDistribucion']);
            Route::get('schedules/capacity', [ScheduleController::class, 'calcularCapacidad']);
            Route::get('schedules', [ScheduleController::class, 'listar']);
            Route::post('schedules', [ScheduleController::class, 'guardar']);
            Route::put('schedules/{id}', [ScheduleController::class, 'guardar'])->whereNumber('id');
            Route::post('unavailabilities', [ScheduleController::class, 'registrarAusencia']);
            Route::post('unavailabilities/preview', [ScheduleController::class, 'previsualizarAusencia']);
            Route::get('unavailabilities/{id}/impacts', [ScheduleController::class, 'consultarImpactoAusencia'])->whereNumber('id');
            Route::post('unavailabilities/{id}/cancel', [ScheduleController::class, 'retirarAusencia'])->whereNumber('id');
        });

        // Los coordinadores clínicos verifican permisos, cupos y pago antes de emitir ficha.
        Route::middleware([RequireAppointments::class, ClinicalAccessAudit::class])->group(function () {
            Route::get('workspace', WorkspaceController::class);
            Route::get('reception-inbox', [ReceptionInboxController::class, 'bandejaRecepcion']);
            Route::get('supervision/patient-accounts', [PatientAccountsController::class, 'cuentasPacientes']);
            Route::get('supervision/payments', [PaymentsController::class, 'consultarPagos']);
            Route::get('online-bookings', [OnlineBookingController::class, 'listarReservasOnline']);
            Route::get('online-bookings/{id}', [OnlineBookingController::class, 'consultarReservaOnline'])->whereNumber('id');
            Route::post('online-bookings', [OnlineBookingController::class, 'iniciarReservaOnline'])->middleware('throttle:12,1');
            Route::post('online-bookings/{id}/status', [OnlineBookingController::class, 'consultarEstadoOnline'])->whereNumber('id')->middleware('throttle:6,1');
            Route::post('online-bookings/{id}/retry', [OnlineBookingController::class, 'reintentarPagoOnline'])->whereNumber('id')->middleware('throttle:6,1');
            Route::post('online-bookings/{id}/cancel', [OnlineBookingController::class, 'descartarReservaOnline'])->whereNumber('id')->middleware('throttle:6,1');
            Route::get('reschedule-requests', [ReprogrammingController::class, 'listarSolicitudesCambio']);
            Route::post('reschedule-requests/{id}/reject', [ReprogrammingController::class, 'rechazarReprogramacion'])->whereNumber('id');
            Route::post('appointments/{id}/reschedule-request', [ReprogrammingController::class, 'solicitarReprogramacion'])->whereNumber('id');
            Route::get('refunds', [RefundRequestController::class, 'listarReembolsos']);
            Route::post('appointments/{id}/refund', [RefundRequestController::class, 'solicitarReembolso'])->whereNumber('id');
            Route::post('refunds/{id}/decision', [RefundResolutionController::class, 'resolverReembolso'])->whereNumber('id');
            Route::post('refunds/{id}/complete', [RefundResolutionController::class, 'registrarEntregaReembolso'])->whereNumber('id');
            Route::get('availability', [AvailableSlotsController::class, 'consultarDisponibilidad']);
            Route::get('appointments', [AppointmentQueryController::class, 'listar']);
            Route::post('appointments', [AppointmentController::class, 'registrar']);
            Route::put('appointments/{id}/reschedule', [ReprogrammingController::class, 'reprogramar'])->whereNumber('id');
            Route::post('appointments/{id}/{action}', [AppointmentActionController::class, 'ejecutarAccion'])->whereNumber('id');
            Route::get('reports', [ReportController::class, 'listar']);
        });
    });
});
