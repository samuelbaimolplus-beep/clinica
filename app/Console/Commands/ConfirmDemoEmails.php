<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Confirma exclusivamente las identidades ficticias conocidas, sin enviar correo. */
class ConfirmDemoEmails extends Command
{
    protected $signature = 'clinic:confirm-demo-emails';

    protected $description = 'Confirma los correos de las cuentas ficticias de demostración existentes.';

    public function handle(): int
    {
        $accounts = [
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
            $accounts['medico'.$number] = ['medico'.$number.'@demo.example', 'Médico'];
        }
        $profiles = 0;
        $confirmed = DB::transaction(function () use ($accounts, &$profiles) {
            $count = 0;
            foreach ($accounts as $username => [$email, $role]) {
                $user = User::where('username', $username)->where('email', $email)->where('role', $role)->lockForUpdate()->first();
                if ($user) {
                    if (! $user->birth_date && $user->role !== 'Paciente') {
                        // Solo personal ficticio antiguo, creado antes de exigir la fecha de nacimiento.
                        $user->birth_date = '1990-01-01';
                        $profiles++;
                    }
                    if (! $user->email_verified_at) {
                        $user->forceFill(['email_verified_at' => now()]);
                        $count++;
                    }
                    if ($user->isDirty()) {
                        $user->save();
                    }
                }
            }

            return $count;
        });
        $this->info($confirmed.' cuentas ficticias confirmadas. No se enviaron correos ni se cambiaron contraseñas.');
        if ($profiles) {
            $this->info($profiles.' perfiles antiguos de personal ficticio completados con una fecha adulta de demostración.');
        }

        return self::SUCCESS;
    }
}
