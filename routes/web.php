<?php

use App\Http\Controllers\AccountActivationController;
use App\Http\Controllers\AccountManagementController;
use App\Http\Controllers\AccountPasswordController;
use App\Http\Controllers\AccountProfileController;
use App\Http\Controllers\AccountRecoveryController;
use App\Http\Controllers\AccountUsernameController;
use App\Http\Controllers\AgendaAdjustmentController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Catalog\DoctorController;
use App\Http\Controllers\Catalog\ImpactController;
use App\Http\Controllers\Catalog\PatientController;
use App\Http\Controllers\Catalog\SpecialtyController;
use App\Http\Controllers\Catalog\StatusController;
use App\Http\Controllers\Catalog\UserController;
use App\Http\Controllers\ClinicalWorkflowController;
use App\Http\Controllers\ManagedPatientAccountController;
use App\Http\Controllers\MedicalAvailabilityController;
use App\Http\Controllers\PayPalWebhookController;
use App\Http\Controllers\ReferenceController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\ScheduleImpactController;
use App\Http\Controllers\SupervisionController;
use App\Http\Middleware\ClinicalAccessAudit;
use App\Http\Middleware\ClinicSession;
use App\Http\Middleware\ReceptionAgendaAccess;
use App\Http\Middleware\RequireAppointments;
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
    Route::get('session', [AuthController::class, 'session']);
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::post('account/email', [AccountRecoveryController::class, 'send'])->middleware('throttle:6,1');
    Route::post('account/activate', AccountActivationController::class)->middleware('throttle:6,1');
    Route::post('account/reset', [AccountRecoveryController::class, 'reset'])->middleware('throttle:6,1');
    Route::post('logout', [AuthController::class, 'logout']);
    Route::middleware(ClinicSession::class)->group(function () {
        Route::patch('account/username', AccountUsernameController::class)->middleware('throttle:6,1');
        Route::patch('account/password', AccountPasswordController::class)->middleware('throttle:6,1');
        Route::get('account/profile', [AccountProfileController::class, 'show']);
        Route::patch('account/profile', [AccountProfileController::class, 'update'])->middleware('throttle:6,1');
        Route::get('supervision/accounts', [AccountManagementController::class, 'index']);
        Route::post('supervision/accounts/{id}/password', [AccountManagementController::class, 'password'])->whereNumber('id')->middleware('throttle:6,1');
        Route::get('references', ReferenceController::class);
        Route::get('catalog-impacts', ImpactController::class);
        Route::get('schedule-impacts', ScheduleImpactController::class);
        Route::get('medical-availability/options', [MedicalAvailabilityController::class, 'options']);
        Route::get('medical-availability', [MedicalAvailabilityController::class, 'index']);
        Route::middleware(ReceptionAgendaAccess::class)->group(function () {
            Route::get('agenda-adjustments', [AgendaAdjustmentController::class, 'index']);
            Route::post('agenda-adjustments/proposal', [AgendaAdjustmentController::class, 'propose']);
            Route::get('agenda-adjustments/protected', [AgendaAdjustmentController::class, 'protectedBlocks']);
            Route::post('agenda-adjustments', [AgendaAdjustmentController::class, 'store']);
            Route::patch('agenda-adjustments/{id}/release', [AgendaAdjustmentController::class, 'release'])->whereNumber('id');
            Route::post('agenda-adjustments/release', [AgendaAdjustmentController::class, 'releaseSelected']);
        });
        Route::middleware('can:view-audit')->group(function () {
            Route::get('audit/filters', [AuditController::class, 'filters']);
            Route::get('audit', [AuditController::class, 'index']);
            Route::get('audit/{id}', [AuditController::class, 'show'])->whereNumber('id');
        });

        // CU03–CU06: contratos explícitos por módulo y autorización antes de consultar datos.
        Route::middleware('can:manage-users')->group(function () {
            Route::get('entities/users/{id}', [UserController::class, 'show'])->whereNumber('id');
            Route::patch('entities/users/{id}/status', StatusController::class)->whereNumber('id')->defaults('catalog', 'users');
            Route::get('entities/users', [UserController::class, 'index']);
            Route::post('entities/users', [UserController::class, 'save']);
            Route::put('entities/users/{id}', [UserController::class, 'save'])->whereNumber('id');
        });
        Route::middleware('can:manage-doctors')->group(function () {
            Route::get('entities/doctors/{id}', [DoctorController::class, 'show'])->whereNumber('id');
            Route::patch('entities/doctors/{id}/status', StatusController::class)->whereNumber('id')->defaults('catalog', 'doctors');
            Route::get('entities/doctors', [DoctorController::class, 'index']);
            Route::post('entities/doctors', [DoctorController::class, 'save']);
            Route::put('entities/doctors/{id}', [DoctorController::class, 'save'])->whereNumber('id');
        });
        Route::middleware('can:manage-specialties')->group(function () {
            Route::get('entities/specialties/{id}', [SpecialtyController::class, 'show'])->whereNumber('id');
            Route::patch('entities/specialties/{id}/status', StatusController::class)->whereNumber('id')->defaults('catalog', 'specialties');
            Route::get('entities/specialties', [SpecialtyController::class, 'index']);
            Route::post('entities/specialties', [SpecialtyController::class, 'save']);
            Route::put('entities/specialties/{id}', [SpecialtyController::class, 'save'])->whereNumber('id');
        });
        Route::middleware('can:manage-patients')->group(function () {
            Route::get('entities/patients/{id}', [PatientController::class, 'show'])->whereNumber('id');
            Route::patch('entities/patients/{id}/status', StatusController::class)->whereNumber('id')->defaults('catalog', 'patients');
            Route::get('entities/patients', [PatientController::class, 'index']);
            Route::post('entities/patients', [PatientController::class, 'save']);
            Route::put('entities/patients/{id}', [PatientController::class, 'save'])->whereNumber('id');
        });
        Route::middleware('can:manage-patient-accounts')->group(function () {
            Route::post('patients/with-account', [ManagedPatientAccountController::class, 'register']);
            Route::post('patients/{id}/account', [ManagedPatientAccountController::class, 'store'])->whereNumber('id');
        });
        Route::middleware('can:manage-schedules')->group(function () {
            Route::put('doctor-quotas', [ScheduleController::class, 'quota']);
            Route::get('schedules/capacity', [ScheduleController::class, 'capacity']);
            Route::get('schedules', [ScheduleController::class, 'index']);
            Route::post('schedules', [ScheduleController::class, 'save']);
            Route::put('schedules/{id}', [ScheduleController::class, 'save'])->whereNumber('id');
            Route::post('unavailabilities', [ScheduleController::class, 'unavailable']);
            Route::post('unavailabilities/preview', [ScheduleController::class, 'preview']);
        });

        // Los coordinadores clínicos verifican permisos, cupos y pago antes de emitir ficha.
        Route::middleware([RequireAppointments::class, ClinicalAccessAudit::class])->group(function () {
            Route::get('reception-inbox', [SupervisionController::class, 'receptionInbox']);
            Route::get('supervision/patient-accounts', [SupervisionController::class, 'patientAccounts']);
            Route::get('supervision/payments', [SupervisionController::class, 'payments']);
            $workflow = ClinicalWorkflowController::class;
            Route::get('online-bookings', [$workflow, 'onlineList']);
            Route::get('online-bookings/{id}', [$workflow, 'onlineShow'])->whereNumber('id');
            Route::post('online-bookings', [$workflow, 'onlineStart'])->middleware('throttle:12,1');
            Route::post('online-bookings/{id}/status', [$workflow, 'onlineStatus'])->whereNumber('id')->middleware('throttle:6,1');
            Route::post('online-bookings/{id}/retry', [$workflow, 'onlineRetry'])->whereNumber('id')->middleware('throttle:6,1');
            Route::post('online-bookings/{id}/cancel', [$workflow, 'onlineCancel'])->whereNumber('id')->middleware('throttle:6,1');
            Route::get('reschedule-requests', [$workflow, 'rescheduleList']);
            Route::post('reschedule-requests/{id}/reject', [$workflow, 'rejectReschedule'])->whereNumber('id');
            Route::post('appointments/{id}/reschedule-request', [$workflow, 'requestReschedule'])->whereNumber('id');
            Route::get('refunds', [$workflow, 'refunds']);
            Route::post('appointments/{id}/refund', [$workflow, 'requestRefund'])->whereNumber('id');
            Route::post('refunds/{id}/decision', [$workflow, 'decideRefund'])->whereNumber('id');
            Route::post('refunds/{id}/complete', [$workflow, 'completeRefund'])->whereNumber('id');
            Route::get('availability', [ScheduleController::class, 'availability']);
            Route::get('appointments', [AppointmentController::class, 'index']);
            Route::post('appointments', [AppointmentController::class, 'store']);
            Route::put('appointments/{id}/reschedule', [AppointmentController::class, 'reschedule'])->whereNumber('id');
            Route::post('appointments/{id}/{action}', [AppointmentController::class, 'action'])->whereNumber('id');
            Route::get('reports', [ReportController::class, 'index']);
        });
    });
});
