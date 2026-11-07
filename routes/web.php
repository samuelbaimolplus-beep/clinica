<?php

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
use App\Http\Controllers\MedicalAvailabilityController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PatientAccountInvitationController;
use App\Http\Controllers\PatientRegistrationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReferenceController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\ScheduleImpactController;
use App\Http\Middleware\ClinicSession;
use App\Http\Middleware\RequireAppointments;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('clinic');
});
Route::view('/terms', 'terms')->name('terms');

Route::prefix('api')->group(function () {
    Route::get('session', [AuthController::class, 'session']);
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::post('register', PatientRegistrationController::class)->middleware('throttle:6,1');
    Route::post('logout', [AuthController::class, 'logout']);
    Route::middleware(ClinicSession::class)->group(function () {
        Route::get('references', ReferenceController::class);
        Route::get('catalog-impacts', ImpactController::class);
        Route::get('schedule-impacts', ScheduleImpactController::class);
        Route::get('medical-availability/options', [MedicalAvailabilityController::class, 'options']);
        Route::get('medical-availability', [MedicalAvailabilityController::class, 'index']);
        Route::middleware('can:manage-agenda-adjustments')->group(function () {
            Route::get('agenda-adjustments', [AgendaAdjustmentController::class, 'index']);
            Route::post('agenda-adjustments/proposal', [AgendaAdjustmentController::class, 'propose']);
            Route::get('agenda-adjustments/protected', [AgendaAdjustmentController::class, 'protectedBlocks']);
            Route::post('agenda-adjustments', [AgendaAdjustmentController::class, 'store']);
            Route::patch('agenda-adjustments/{id}/release', [AgendaAdjustmentController::class, 'release'])->whereNumber('id');
        });
        Route::middleware('can:view-audit')->group(function () {
            Route::get('audit/filters', [AuditController::class, 'filters']);
            Route::get('audit', [AuditController::class, 'index']);
            Route::get('audit/{id}', [AuditController::class, 'show'])->whereNumber('id');
        });
        Route::get('profile', [ProfileController::class, 'show']);
        Route::put('profile', [ProfileController::class, 'update']);
        Route::put('profile/password', PasswordController::class)->middleware('throttle:6,1');

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
            Route::post('patients/{id}/account-invitation', PatientAccountInvitationController::class)->whereNumber('id')->middleware('throttle:12,1');
            Route::get('entities/patients', [PatientController::class, 'index']);
            Route::post('entities/patients', [PatientController::class, 'save']);
            Route::put('entities/patients/{id}', [PatientController::class, 'save'])->whereNumber('id');
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

        // Implementación anterior conservada; pendiente integrar pago obligatorio y cupos.
        Route::middleware(RequireAppointments::class)->group(function () {
            Route::get('availability', [ScheduleController::class, 'availability']);
            Route::get('appointments', [AppointmentController::class, 'index']);
            Route::post('appointments', [AppointmentController::class, 'store']);
            Route::put('appointments/{id}/reschedule', [AppointmentController::class, 'reschedule'])->whereNumber('id');
            Route::post('appointments/{id}/{action}', [AppointmentController::class, 'action'])->whereNumber('id');
            Route::get('reports', [ReportController::class, 'index']);
        });
    });
});
