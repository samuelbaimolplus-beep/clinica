<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\EmailActivation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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

        return DB::transaction(function () use ($email, $password) {
            $user = User::create(['name' => $this->option('nombre'), 'email' => $email, 'password' => $password, 'role' => 'Administrador', 'status' => 'Activo']);
            if (! Storage::disk('local')->put('acceso-inicial.txt', "Clínica Vida Salud — acceso local inicial\nUsuario: {$user->username}\nCorreo: {$email}\nContraseña: {$password}\nCambie la contraseña desde Gestionar usuarios y roles.\nNo publique ni comparta este archivo.\n")) {
                throw new \RuntimeException('No se pudo guardar el acceso privado; no se creó la cuenta.');
            }
            $this->info('Administrador creado. Acceso guardado en storage/app/private/acceso-inicial.txt (fuera de Git).');
            DB::afterCommit(fn () => app(EmailActivation::class)->send($user));
            $this->info('Debe activar la cuenta con el código enviado a su correo antes de acceder.');

            return self::SUCCESS;
        });
    }
}
