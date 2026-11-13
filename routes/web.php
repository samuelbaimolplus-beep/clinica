<?php

/** Puntos de entrada de web: agrupan operaciones y sus controles de acceso antes de llegar al dominio. */

use App\Compartido\Catalogos\Http\ControladorEstadoCatalogos;
use App\Compartido\Catalogos\Http\ControladorImpactoCatalogos;
use App\Compartido\Http\ControladorReferencias;
use App\Http\Middleware\ClinicalAccessAudit;
use App\Http\Middleware\ClinicSession;
use App\Http\Middleware\ReceptionAgendaAccess;
use App\Http\Middleware\RequireAppointments;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Http\ControladorActivacionCuenta;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Http\ControladorClaveCuenta;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Http\ControladorNombreUsuario;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Http\ControladorPerfilCuenta;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Http\ControladorRecuperacionCuenta;
use App\Paquetes\P01_UsuariosYAcceso\CU01_IniciarSesion\Http\AuthController;
use App\Paquetes\P01_UsuariosYAcceso\CU02_CerrarSesion\Http\LogoutController;
use App\Paquetes\P01_UsuariosYAcceso\CU03_GestionarUsuariosYRoles\Http\ControladorGestionCuentas;
use App\Paquetes\P01_UsuariosYAcceso\CU03_GestionarUsuariosYRoles\Http\ControladorUsuarios;
use App\Paquetes\P01_UsuariosYAcceso\CU23_RegistrarCuentaDePaciente\Http\ControladorAltaCuentaPaciente;
use App\Paquetes\P01_UsuariosYAcceso\CU23_RegistrarCuentaDePaciente\Http\ControladorCuentasPacientes;
use App\Paquetes\P02_PersonalMedicoYEspecialidades\CU04_GestionarMedicos\Http\ControladorMedicos;
use App\Paquetes\P02_PersonalMedicoYEspecialidades\CU05_GestionarEspecialidades\Http\ControladorEspecialidades;
use App\Paquetes\P03_Pacientes\CU06_GestionarPacientes\Http\ControladorPacientes;
use App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Http\ControladorHorarios;
use App\Paquetes\P04_HorariosYDisponibilidad\CU08_ConsultarDisponibilidadMedica\Http\ControladorDisponibilidadMedica;
use App\Paquetes\P04_HorariosYDisponibilidad\CU08_ConsultarDisponibilidadMedica\Http\ControladorFranjasDisponibles;
use App\Paquetes\P04_HorariosYDisponibilidad\CU24_GestionarBloqueosProvisionales\Http\ControladorBloqueosProvisionales;
use App\Paquetes\P04_HorariosYDisponibilidad\CU25_HabilitarCuposOnline\Http\ControladorHabilitarCuposOnline;
use App\Paquetes\P05_CitasMedicas\Compartido\Http\ControladorAccionesCita;
use App\Paquetes\P05_CitasMedicas\Compartido\Http\ControladorConsultaCitas;
use App\Paquetes\P05_CitasMedicas\Compartido\Http\ControladorPanelInicio;
use App\Paquetes\P05_CitasMedicas\CU09_ProgramarCitaMedica\Http\ControladorProgramarCita;
use App\Paquetes\P05_CitasMedicas\CU10_ReprogramarCitaMedica\Http\ControladorBandejaRecepcion;
use App\Paquetes\P05_CitasMedicas\CU10_ReprogramarCitaMedica\Http\ControladorReprogramacion;
use App\Paquetes\P05_CitasMedicas\CU17_ConsultarCitasAfectadas\Http\ControladorCitasAfectadas;
use App\Paquetes\P06_HistorialYReportes\CU16_GenerarReportesAdministrativos\Http\ControladorReportes;
use App\Paquetes\P07_ReservasPagosYSeguimientos\Compartido\Integraciones\ControladorNotificacionesPayPal;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU18_ReservarCitaOnline\Http\ControladorReservasOnline;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU19_VerificarPagoOExencion\Http\ControladorPagos;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU20_SolicitarReembolso\Http\ControladorSolicitudReembolso;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU26_ResolverSolicitudDeReembolso\Http\ControladorResolucionReembolso;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Http\ControladorBitacora;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('clinic');
});
Route::view('/terms', 'terms')->name('terms');
Route::post('/pagos/paypal/webhook', ControladorNotificacionesPayPal::class)->middleware('throttle:60,1');
// El retorno del navegador no confirma pagos; solo vuelve a la reserva del usuario.
Route::get('/pagos/paypal/{result}', function (Request $request) {
    $id = filter_var($request->query('operacion'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return redirect('/'.($id ? '?paypal_booking='.$id : '').'#reservar-cita');
})->whereIn('result', ['retorno', 'cancelado']);
Route::get('/password/reset/{token}', fn (string $token) => view('auth.reset-password', ['token' => $token]));

Route::prefix('api')->group(function () {
    Route::get('session', [AuthController::class, 'consultarSesion']);
    Route::post('login', [AuthController::class, 'iniciarSesion'])->middleware('throttle:6,1');
    Route::post('account/email', [ControladorRecuperacionCuenta::class, 'enviar'])->middleware('throttle:6,1');
    Route::post('account/activate', ControladorActivacionCuenta::class)->middleware('throttle:6,1');
    Route::post('account/reset', [ControladorRecuperacionCuenta::class, 'restablecer'])->middleware('throttle:6,1');
    Route::post('logout', [LogoutController::class, 'cerrarSesion']);
    Route::middleware(ClinicSession::class)->group(function () {
        Route::patch('account/username', ControladorNombreUsuario::class)->middleware('throttle:6,1');
        Route::patch('account/password', ControladorClaveCuenta::class)->middleware('throttle:6,1');
        Route::get('account/profile', [ControladorPerfilCuenta::class, 'consultarDetalle']);
        Route::patch('account/profile', [ControladorPerfilCuenta::class, 'actualizar'])->middleware('throttle:6,1');
        Route::get('supervision/accounts', [ControladorGestionCuentas::class, 'listar']);
        Route::post('supervision/accounts/{id}/password', [ControladorGestionCuentas::class, 'cambiarContrasena'])->whereNumber('id')->middleware('throttle:6,1');
        Route::get('references', ControladorReferencias::class);
        Route::get('catalog-impacts', ControladorImpactoCatalogos::class);
        Route::get('schedule-impacts', ControladorCitasAfectadas::class);
        Route::get('medical-availability/options', [ControladorDisponibilidadMedica::class, 'opciones']);
        Route::get('medical-availability', [ControladorDisponibilidadMedica::class, 'listar']);
        Route::middleware(ReceptionAgendaAccess::class)->group(function () {
            Route::get('agenda-adjustments', [ControladorBloqueosProvisionales::class, 'listar']);
            Route::post('agenda-adjustments/proposal', [ControladorBloqueosProvisionales::class, 'proponer']);
            Route::get('agenda-adjustments/protected', [ControladorHabilitarCuposOnline::class, 'franjasProtegidas']);
            Route::post('agenda-adjustments', [ControladorBloqueosProvisionales::class, 'registrar']);
            Route::patch('agenda-adjustments/{id}/release', [ControladorBloqueosProvisionales::class, 'liberar'])->whereNumber('id');
            Route::post('agenda-adjustments/release', [ControladorBloqueosProvisionales::class, 'liberarSeleccionados']);
        });
        Route::middleware('can:view-audit')->group(function () {
            Route::get('audit/filters', [ControladorBitacora::class, 'filtros']);
            Route::get('audit', [ControladorBitacora::class, 'listar']);
            Route::get('audit/{id}', [ControladorBitacora::class, 'consultarDetalle'])->whereNumber('id');
        });

        // CU03–CU06: contratos explícitos por módulo y autorización antes de consultar datos.
        Route::middleware('can:manage-users')->group(function () {
            Route::get('entities/users/{id}', [ControladorUsuarios::class, 'consultarDetalle'])->whereNumber('id');
            Route::patch('entities/users/{id}/status', ControladorEstadoCatalogos::class)->whereNumber('id')->defaults('catalog', 'users');
            Route::get('entities/users', [ControladorUsuarios::class, 'listar']);
            Route::post('entities/users', [ControladorUsuarios::class, 'guardar']);
            Route::put('entities/users/{id}', [ControladorUsuarios::class, 'guardar'])->whereNumber('id');
        });
        Route::middleware('can:manage-doctors')->group(function () {
            Route::get('entities/doctors/{id}', [ControladorMedicos::class, 'consultarDetalle'])->whereNumber('id');
            Route::patch('entities/doctors/{id}/status', ControladorEstadoCatalogos::class)->whereNumber('id')->defaults('catalog', 'doctors');
            Route::get('entities/doctors', [ControladorMedicos::class, 'listar']);
            Route::post('entities/doctors', [ControladorMedicos::class, 'guardar']);
            Route::put('entities/doctors/{id}', [ControladorMedicos::class, 'guardar'])->whereNumber('id');
        });
        Route::middleware('can:manage-specialties')->group(function () {
            Route::get('entities/specialties/{id}', [ControladorEspecialidades::class, 'consultarDetalle'])->whereNumber('id');
            Route::patch('entities/specialties/{id}/status', ControladorEstadoCatalogos::class)->whereNumber('id')->defaults('catalog', 'specialties');
            Route::get('entities/specialties', [ControladorEspecialidades::class, 'listar']);
            Route::post('entities/specialties', [ControladorEspecialidades::class, 'guardar']);
            Route::put('entities/specialties/{id}', [ControladorEspecialidades::class, 'guardar'])->whereNumber('id');
        });
        Route::middleware('can:manage-patients')->group(function () {
            Route::get('entities/patients/{id}', [ControladorPacientes::class, 'consultarDetalle'])->whereNumber('id');
            Route::patch('entities/patients/{id}/status', ControladorEstadoCatalogos::class)->whereNumber('id')->defaults('catalog', 'patients');
            Route::get('entities/patients', [ControladorPacientes::class, 'listar']);
            Route::post('entities/patients', [ControladorPacientes::class, 'guardar']);
            Route::put('entities/patients/{id}', [ControladorPacientes::class, 'guardar'])->whereNumber('id');
        });
        Route::middleware('can:manage-patient-accounts')->group(function () {
            Route::post('patients/with-account', [ControladorAltaCuentaPaciente::class, 'registrar']);
            Route::post('patients/{id}/account', [ControladorAltaCuentaPaciente::class, 'crearCuentaParaPaciente'])->whereNumber('id');
        });
        Route::middleware('can:manage-schedules')->group(function () {
            Route::put('doctor-quotas', [ControladorHorarios::class, 'guardarDistribucion']);
            Route::get('schedules/capacity', [ControladorHorarios::class, 'calcularCapacidad']);
            Route::get('schedules', [ControladorHorarios::class, 'listar']);
            Route::post('schedules', [ControladorHorarios::class, 'guardar']);
            Route::put('schedules/{id}', [ControladorHorarios::class, 'guardar'])->whereNumber('id');
            Route::post('unavailabilities', [ControladorHorarios::class, 'registrarAusencia']);
            Route::post('unavailabilities/preview', [ControladorHorarios::class, 'previsualizarAusencia']);
            Route::get('unavailabilities/{id}/impacts', [ControladorHorarios::class, 'consultarImpactoAusencia'])->whereNumber('id');
            Route::post('unavailabilities/{id}/cancel', [ControladorHorarios::class, 'retirarAusencia'])->whereNumber('id');
        });

        // Los coordinadores clínicos verifican permisos, cupos y pago antes de emitir ficha.
        Route::middleware([RequireAppointments::class, ClinicalAccessAudit::class])->group(function () {
            Route::get('workspace', ControladorPanelInicio::class);
            Route::get('reception-inbox', [ControladorBandejaRecepcion::class, 'bandejaRecepcion']);
            Route::get('supervision/patient-accounts', [ControladorCuentasPacientes::class, 'cuentasPacientes']);
            Route::get('supervision/payments', [ControladorPagos::class, 'consultarPagos']);
            Route::get('online-bookings', [ControladorReservasOnline::class, 'listarReservasOnline']);
            Route::get('online-bookings/{id}', [ControladorReservasOnline::class, 'consultarReservaOnline'])->whereNumber('id');
            Route::post('online-bookings', [ControladorReservasOnline::class, 'iniciarReservaOnline'])->middleware('throttle:12,1');
            Route::post('online-bookings/{id}/status', [ControladorReservasOnline::class, 'consultarEstadoOnline'])->whereNumber('id')->middleware('throttle:6,1');
            Route::post('online-bookings/{id}/retry', [ControladorReservasOnline::class, 'reintentarPagoOnline'])->whereNumber('id')->middleware('throttle:6,1');
            Route::post('online-bookings/{id}/cancel', [ControladorReservasOnline::class, 'descartarReservaOnline'])->whereNumber('id')->middleware('throttle:6,1');
            Route::get('reschedule-requests', [ControladorReprogramacion::class, 'listarSolicitudesCambio']);
            Route::post('reschedule-requests/{id}/reject', [ControladorReprogramacion::class, 'rechazarReprogramacion'])->whereNumber('id');
            Route::post('appointments/{id}/reschedule-request', [ControladorReprogramacion::class, 'solicitarReprogramacion'])->whereNumber('id');
            Route::get('refunds', [ControladorSolicitudReembolso::class, 'listarReembolsos']);
            Route::post('appointments/{id}/refund', [ControladorSolicitudReembolso::class, 'solicitarReembolso'])->whereNumber('id');
            Route::post('refunds/{id}/decision', [ControladorResolucionReembolso::class, 'resolverReembolso'])->whereNumber('id');
            Route::post('refunds/{id}/complete', [ControladorResolucionReembolso::class, 'registrarEntregaReembolso'])->whereNumber('id');
            Route::get('availability', [ControladorFranjasDisponibles::class, 'consultarDisponibilidad']);
            Route::get('appointments', [ControladorConsultaCitas::class, 'listar']);
            Route::post('appointments', [ControladorProgramarCita::class, 'registrar']);
            Route::put('appointments/{id}/reschedule', [ControladorReprogramacion::class, 'reprogramar'])->whereNumber('id');
            Route::post('appointments/{id}/{action}', [ControladorAccionesCita::class, 'ejecutarAccion'])->whereNumber('id');
            Route::get('reports', [ControladorReportes::class, 'listar']);
        });
    });
});
