<?php

/** Componente CreateClinicSuperadmin: colabora con los módulos del sistema sin reemplazar sus controles del servidor. */

namespace App\Console\Commands;

use App\Models\Usuario;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\ActivacionPorCorreo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CrearSuperadminClinica extends Command
{
    protected $signature = 'clinica:crear-superadmin {email} {--nombre=Superadministración} {--nacimiento=}';

    protected $description = 'Crea el primer Superadmin; guarda la contraseña aleatoria solo en un archivo local privado.';

    /** Ejecuta la responsabilidad de este middleware, comando o trabajo; sus controles se explican en el encabezado de la clase. */
    public function handle(): int
    {
        $validation = validator(['birth_date' => $this->option('nacimiento')], ['birth_date' => 'required|date_format:Y-m-d|before_or_equal:'.today()->subYearsNoOverflow(18)->toDateString()]);
        if ($validation->fails()) {
            $this->error('Indique --nacimiento=AAAA-MM-DD, con al menos 18 años cumplidos.');

            return self::FAILURE;
        }
        $correo = mb_strtolower(trim($this->argument('email')));
        if (! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $this->error('Ingrese un correo válido.');

            return self::FAILURE;
        }

        return DB::transaction(function () use ($correo) {
            DB::select('SELECT pg_advisory_xact_lock(7331026)');
            if (Usuario::where('role', 'Superadmin')->exists() || Usuario::where('email', $correo)->exists()) {
                $this->error('Ya existe un Superadmin o una cuenta con ese correo. No se modificó ninguna cuenta.');

                return self::FAILURE;
            }
            $contrasena = Str::password(24, symbols: false);
            $usuario = Usuario::create(['birth_date' => $this->option('nacimiento'), 'name' => $this->option('nombre'), 'email' => $correo, 'password' => $contrasena, 'role' => 'Superadmin', 'status' => 'Activo']);
            if (! Storage::disk('local')->put('acceso-superadmin.txt', "Clínica Vida Salud — acceso Superadmin\nUsuario: {$usuario->username}\nCorreo: {$correo}\nContraseña: {$contrasena}\nConserve este archivo en privado. Puede cambiar la contraseña en Usuarios y roles.\n")) {
                throw new \RuntimeException('No se pudo guardar el acceso privado; no se creó la cuenta.');
            }
            $this->info('Superadmin creado. Acceso en storage/app/private/acceso-superadmin.txt (excluido de Git).');
            DB::afterCommit(fn () => app(ActivacionPorCorreo::class)->enviar($usuario));
            $this->info('Debe activar la cuenta con el código enviado a su correo antes de acceder.');

            return self::SUCCESS;
        });
    }
}
