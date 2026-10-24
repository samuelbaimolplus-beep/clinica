<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CreateClinicAdmin extends Command
{
    protected $signature = 'clinica:crear-admin {email} {--nombre=Administración}';

    protected $description = 'Crea la primera cuenta administrativa y guarda su acceso en un archivo local privado.';

    public function handle(): int
    {
        if (User::where('role', 'Administrador')->where('status', 'Activo')->exists()) {
            $this->error('Ya existe un administrador activo. Gestione las cuentas desde la aplicación.');

            return self::FAILURE;
        }
        $email = mb_strtolower(trim($this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || User::where('email', $email)->exists()) {
            $this->error('El correo no es válido o ya está registrado.');

            return self::FAILURE;
        }
        $password = Str::password(20, symbols: false);
        Storage::disk('local')->put('acceso-inicial.txt', "Clínica Vida Salud — acceso local inicial\nCorreo: {$email}\nContraseña: {$password}\nCambie la contraseña desde Gestionar usuarios y roles.\nNo publique ni comparta este archivo.\n");
        User::create(['name' => $this->option('nombre'), 'email' => $email, 'password' => $password, 'role' => 'Administrador', 'status' => 'Activo']);
        $this->info('Administrador creado. Acceso guardado en storage/app/private/acceso-inicial.txt (fuera de Git).');

        return self::SUCCESS;
    }
}
