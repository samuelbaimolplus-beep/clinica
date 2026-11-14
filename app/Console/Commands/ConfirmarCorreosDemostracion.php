<?php

namespace App\Console\Commands;

use App\Models\Usuario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Confirma exclusivamente las identidades ficticias conocidas, sin enviar correo. */
class ConfirmarCorreosDemostracion extends Command
{
    protected $signature = 'clinic:confirm-demo-emails';

    protected $description = 'Confirma los correos de las cuentas ficticias de demostración existentes.';

    public function handle(): int
    {
        $cuentas = [
            'demo.admin' => ['demo.admin@example.test', 'Administrador'],
            'demo.medico' => ['demo.medico@example.test', 'Médico'],
            'demo.medico2' => ['demo.medico2@example.test', 'Médico'],
            'demo.recepcion' => ['demo.recepcion@example.test', 'Recepcionista'],
            'demo.recepcion2' => ['demo.recepcion2@example.test', 'Recepcionista'],
            'demo.paciente1' => ['demo.paciente1@example.test', 'Paciente'],
            'demo.paciente2' => ['demo.paciente2@example.test', 'Paciente'],
            'recepcion' => ['recepcion@demo.example', 'Recepcionista'],
        ];
        foreach (range(1, 5) as $number) {
            $cuentas['medico'.$number] = ['medico'.$number.'@demo.example', 'Médico'];
        }
        $perfiles = 0;
        $confirmadas = DB::transaction(function () use ($cuentas, &$perfiles) {
            $cantidad = 0;
            foreach ($cuentas as $username => [$correo, $rol]) {
                $usuario = Usuario::where('username', $username)->where('email', $correo)->where('role', $rol)->lockForUpdate()->first();
                if ($usuario) {
                    if (! $usuario->birth_date && $usuario->role !== 'Paciente') {
                        // Solo personal ficticio antiguo, creado antes de exigir la fecha de nacimiento.
                        $usuario->birth_date = '1990-01-01';
                        $perfiles++;
                    }
                    if (! $usuario->email_verified_at) {
                        $usuario->forceFill(['email_verified_at' => now()]);
                        $cantidad++;
                    }
                    if ($usuario->isDirty()) {
                        $usuario->save();
                    }
                }
            }

            return $cantidad;
        });
        $this->info($confirmadas.' cuentas ficticias confirmadas. No se enviaron correos ni se cambiaron contraseñas.');
        if ($perfiles) {
            $this->info($perfiles.' perfiles antiguos de personal ficticio completados con una fecha adulta de demostración.');
        }

        return self::SUCCESS;
    }
}
