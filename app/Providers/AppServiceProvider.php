<?php

namespace App\Providers;

use App\Models\AgendaAdjustment;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorQuota;
use App\Models\Patient;
use App\Models\Schedule;
use App\Models\Specialty;
use App\Models\Unavailability;
use App\Models\User;
use App\Observers\ClinicAuditObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(fn ($user, $token) => url('/password/reset/'.$token).'?'.http_build_query(['email' => $user->email]));
        Gate::define('view-audit', fn (User $user) => $user->role === 'Administrador');
        Gate::define('manage-agenda-adjustments', fn (User $user) => $user->role === 'Recepcionista');
        foreach ([User::class, Doctor::class,
            Specialty::class, Patient::class,
            Schedule::class, Unavailability::class,
            Appointment::class, DoctorQuota::class, AgendaAdjustment::class] as $model) {
            $model::observe(ClinicAuditObserver::class);
        }
        foreach (['users' => 'Administrador', 'doctors' => 'Administrador',
            'specialties' => 'Administrador', 'schedules' => 'Administrador',
            'patients' => 'Recepcionista'] as $module => $role) {
            Gate::define('manage-'.$module,
                fn (User $user) => $user->role === $role);
        }
    }
}
