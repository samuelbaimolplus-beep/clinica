<?php

namespace App\Providers;

use App\Database\SpanishPostgresConnection;
use App\Models\AgendaAdjustment;
use App\Models\Appointment;
use App\Models\BookingRequest;
use App\Models\Doctor;
use App\Models\DoctorQuota;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Models\RescheduleRequest;
use App\Models\Schedule;
use App\Models\Specialty;
use App\Models\Unavailability;
use App\Models\User;
use App\Observers\ClinicAuditObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Connection::resolverFor('pgsql',
            fn ($pdo, $database, $prefix, $config) => new SpanishPostgresConnection($pdo, $database, $prefix, $config));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(fn ($user, $token) => url('/password/reset/'.$token).'?'.http_build_query(['email' => $user->email]));
        Gate::define('view-audit', fn (User $user) => $user->hasStaffRole('Administrador'));
        Gate::define('manage-agenda-adjustments', fn (User $user) => $user->hasStaffRole('Recepcionista'));
        Gate::define('manage-patient-accounts', fn (User $user) => $user->hasStaffRole('Administrador', 'Recepcionista'));
        Gate::define('manage-patients', fn (User $user) => $user->hasStaffRole('Administrador', 'Recepcionista'));
        foreach ([User::class, Doctor::class,
            Specialty::class, Patient::class,
            Schedule::class, Unavailability::class,
            Appointment::class, DoctorQuota::class, AgendaAdjustment::class,
            BookingRequest::class, Payment::class, RescheduleRequest::class, RefundRequest::class] as $model) {
            $model::observe(ClinicAuditObserver::class);
        }
        foreach (['users' => 'Administrador', 'doctors' => 'Administrador',
            'specialties' => 'Administrador', 'schedules' => 'Administrador'] as $module => $role) {
            Gate::define('manage-'.$module,
                fn (User $user) => $user->hasStaffRole($role));
        }
    }
}
