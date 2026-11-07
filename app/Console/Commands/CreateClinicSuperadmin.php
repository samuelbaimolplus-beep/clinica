<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CreateClinicSuperadmin extends Command
{
    protected $signature = 'clinica:crear-superadmin {email} {--nombre=Superadministración}';

    protected $description = 'Crea el primer Superadmin; guarda la contraseña aleatoria solo en un archivo local privado.';

    public function handle(): int
    {
        $email = mb_strtolower(trim($this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Ingrese un correo válido.');

            return self::FAILURE;
        }

        return DB::transaction(function () use ($email) {
            DB::select('SELECT pg_advisory_xact_lock(7331026)');
            if (User::where('role', 'Superadmin')->exists() || User::where('email', $email)->exists()) {
                $this->error('Ya existe un Superadmin o una cuenta con ese correo. No se modificó ninguna cuenta.');

                return self::FAILURE;
            }
            $password = Str::password(24, symbols: false);
            $user = User::create(['name' => $this->option('nombre'), 'email' => $email, 'password' => $password, 'role' => 'Superadmin', 'status' => 'Activo']);
            if (! Storage::disk('local')->put('acceso-superadmin.txt', "Clínica Vida Salud — acceso Superadmin\nUsuario: {$user->username}\nCorreo: {$email}\nContraseña: {$password}\nConserve este archivo en privado. Puede cambiar la contraseña en Usuarios y roles.\n")) {
                throw new \RuntimeException('No se pudo guardar el acceso privado; no se creó la cuenta.');
            }
            $this->info('Superadmin creado. Acceso en storage/app/private/acceso-superadmin.txt (excluido de Git).');

            return self::SUCCESS;
        });
    }
}
