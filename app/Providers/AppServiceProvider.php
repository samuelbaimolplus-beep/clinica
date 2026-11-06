<?php

namespace App\Providers;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorQuota;
use App\Models\Patient;
use App\Models\Schedule;
use App\Models\Specialty;
use App\Models\Unavailability;
use App\Models\User;
use App\Observers\ClinicAuditObserver;
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
        foreach ([User::class, Doctor::class,
            Specialty::class, Patient::class,
            Schedule::class, Unavailability::class,
            Appointment::class, DoctorQuota::class] as $model) {
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
