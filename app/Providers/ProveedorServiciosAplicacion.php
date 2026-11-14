<?php

/** Registra adaptación PostgreSQL, permisos, observadores y vínculos de recuperación de Laravel. */

namespace App\Providers;

use App\Database\ConexionPostgresEspanol;
use App\Models\AjusteAgenda;
use App\Models\AusenciaMedica;
use App\Models\Cita;
use App\Models\CupoMedico;
use App\Models\Especialidad;
use App\Models\Horario;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Pago;
use App\Models\SolicitudReembolso;
use App\Models\SolicitudReprogramacion;
use App\Models\SolicitudReserva;
use App\Models\Usuario;
use App\Observers\ObservadorAuditoriaClinica;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class ProveedorServiciosAplicacion extends ServiceProvider
{
    /**
     * Registra los servicios de la aplicación.
     */
    public function register(): void
    {
        Connection::resolverFor('pgsql',
            fn ($pdo, $baseDatos, $prefijo, $configuracion) => new ConexionPostgresEspanol($pdo, $baseDatos, $prefijo, $configuracion));
    }

    /**
     * Inicializa los servicios de la aplicación.
     */
    public function boot(): void
    {
        if ($this->app->environment('production') && parse_url(config('app.url'), PHP_URL_SCHEME) === 'https') {
            URL::forceScheme('https');
        }
        ResetPassword::createUrlUsing(fn ($usuario, $token) => url('/password/reset/'.$token).'?'.http_build_query(['email' => $usuario->email]));
        Gate::define('view-audit', fn (Usuario $usuario) => $usuario->tieneRolPersonal('Administrador'));
        Gate::define('manage-agenda-adjustments', fn (Usuario $usuario) => $usuario->tieneRolPersonal('Recepcionista'));
        Gate::define('manage-patient-accounts', fn (Usuario $usuario) => $usuario->tieneRolPersonal('Administrador', 'Recepcionista'));
        Gate::define('manage-patients', fn (Usuario $usuario) => $usuario->tieneRolPersonal('Administrador', 'Recepcionista'));
        foreach ([Usuario::class, Medico::class,
            Especialidad::class, Paciente::class,
            Horario::class, AusenciaMedica::class,
            Cita::class, CupoMedico::class, AjusteAgenda::class,
            SolicitudReserva::class, Pago::class, SolicitudReprogramacion::class, SolicitudReembolso::class] as $modelo) {
            $modelo::observe(ObservadorAuditoriaClinica::class);
        }
        foreach (['users' => 'Administrador', 'doctors' => 'Administrador',
            'specialties' => 'Administrador', 'schedules' => 'Administrador'] as $modulo => $rol) {
            Gate::define('manage-'.$modulo,
                fn (Usuario $usuario) => $usuario->tieneRolPersonal($rol));
        }
    }
}
