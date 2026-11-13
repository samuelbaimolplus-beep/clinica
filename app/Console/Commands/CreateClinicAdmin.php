<?php

/** Componente CreateClinicAdmin: colabora con los módulos del sistema sin reemplazar sus controles del servidor. */

namespace App\Console\Commands;

use App\Models\User;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\ActivacionPorCorreo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CreateClinicAdmin extends Command
{
    protected $signature = 'clinica:crear-admin {email} {--nombre=Administración} {--nacimiento=}';

    protected $description = 'Crea la primera cuenta administrativa y guarda su acceso en un archivo local privado.';

    /** Ejecuta la responsabilidad de este middleware, comando o trabajo; sus controles se explican en el encabezado de la clase. */
    public function handle(): int
    {
        if (User::where('role', 'Administrador')->where('status', 'Activo')->exists()) {
            $this->error('Ya existe un administrador activo. Gestione las cuentas desde la aplicación.');

            return self::FAILURE;
        }
        $validation = validator(['birth_date' => $this->option('nacimiento')], ['birth_date' => 'required|date_format:Y-m-d|before_or_equal:'.today()->subYearsNoOverflow(18)->toDateString()]);
        if ($validation->fails()) {
            $this->error('Indique --nacimiento=AAAA-MM-DD, con al menos 18 años cumplidos.');

            return self::FAILURE;
        }
        $email = mb_strtolower(trim($this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || User::where('email', $email)->exists()) {
            $this->error('El correo no es válido o ya está registrado.');

            return self::FAILURE;
        }
        $password = Str::password(20, symbols: false);

        return DB::transaction(function () use ($email, $password) {
            $user = User::create(['birth_date' => $this->option('nacimiento'), 'name' => $this->option('nombre'), 'email' => $email, 'password' => $password, 'role' => 'Administrador', 'status' => 'Activo']);
            if (! Storage::disk('local')->put('acceso-inicial.txt', "Clínica Vida Salud — acceso local inicial\nUsuario: {$user->username}\nCorreo: {$email}\nContraseña: {$password}\nCambie la contraseña desde Gestionar usuarios y roles.\nNo publique ni comparta este archivo.\n")) {
                throw new \RuntimeException('No se pudo guardar el acceso privado; no se creó la cuenta.');
            }
            $this->info('Administrador creado. Acceso guardado en storage/app/private/acceso-inicial.txt (fuera de Git).');
            DB::afterCommit(fn () => app(ActivacionPorCorreo::class)->enviar($user));
            $this->info('Debe activar la cuenta con el código enviado a su correo antes de acceder.');

            return self::SUCCESS;
        });
    }
}
