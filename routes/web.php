<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EntityController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ScheduleController;
use App\Http\Middleware\ClinicSession;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('clinic');
});

Route::prefix('api')->group(function () {
    Route::get('session', [AuthController::class, 'session']);
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::post('logout', [AuthController::class, 'logout']);
    Route::middleware(ClinicSession::class)->group(function () {
        Route::get('references', [EntityController::class, 'references']);
        Route::get('entities/{entity}', [EntityController::class, 'index']);
        Route::post('entities/{entity}', [EntityController::class, 'save']);
        Route::put('entities/{entity}/{id}', [EntityController::class, 'save'])->whereNumber('id');
        Route::get('availability', [ScheduleController::class, 'availability']);
        Route::get('schedules', [ScheduleController::class, 'index']);
        Route::post('schedules', [ScheduleController::class, 'save']);
        Route::post('unavailabilities', [ScheduleController::class, 'unavailable']);
        Route::post('unavailabilities/preview', [ScheduleController::class, 'preview']);
        Route::get('appointments', [AppointmentController::class, 'index']);
        Route::post('appointments', [AppointmentController::class, 'store']);
        Route::put('appointments/{id}/reschedule', [AppointmentController::class, 'reschedule'])->whereNumber('id');
        Route::post('appointments/{id}/{action}', [AppointmentController::class, 'action'])->whereNumber('id');
        Route::get('reports', [ReportController::class, 'index']);
    });
});
