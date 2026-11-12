<?php

/** Prepara exclusivamente identidades ficticias locales y conserva contraseñas de demostración existentes. */

namespace App\Console\Commands;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Schedule;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CreateDemoAccounts extends Command
{
    protected $signature = 'clinica:crear-cuentas-demo';

    protected $description = 'Prepara cuentas ficticias activadas para probar los roles en el entorno local.';

    /** Ejecuta la responsabilidad de este middleware, comando o trabajo; sus controles se explican en el encabezado de la clase. */
    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Las cuentas de demostración solo se crean en local o testing.');

            return self::FAILURE;
        }
        $path = 'accesos-demostracion.json';
        if (Storage::disk('local')->exists($path)) {
            // Solo identidades ficticias conocidas; no se inventan fechas de usuarios reales.
            DB::transaction(function () {
                foreach (['demo.medico', 'demo.recepcion', 'demo.paciente1', 'demo.paciente2'] as $username) {
                    $user = User::where('username', $username)->where('email', $username.'@example.test')->first();
                    if (! $user || ! str_contains($user->name, 'Demostración')) {
                        continue;
                    }
                    if ($user->role === 'Paciente' && ! str_starts_with($user->patient?->identity ?? '', 'DEMO-FLUJO-PAC-')) {
                        continue;
                    }
                    $user->birth_date = $user->role === 'Paciente' ? $user->patient->birth_date : '2000-01-01';
                    if ($user->role === 'Paciente') {
                        $user->terms_accepted_at ??= now();
                        $user->terms_version = config('clinic.terms_version');
                    }
                    $user->save();
                }
            });
            $this->info('Las cuentas ya se prepararon. Sus accesos están en storage/app/private/'.$path);

            return self::SUCCESS;
        }

        $credentials = DB::transaction(function () use ($path) {
            $specialty = Specialty::firstOrCreate(['name' => 'Consulta de demostración'], [
                'description' => 'Especialidad ficticia para recorrer el sistema local.', 'status' => 'Activo', 'fee_minor' => 10000,
            ]);
            $doctor = Doctor::firstOrCreate(['registration' => 'DEMO-FLUJO-MED-001'], [
                'first_name' => 'Andrea', 'last_name' => 'Demostración', 'email' => 'medico.flujo@example.test',
                'phone' => '70000001', 'status' => 'Activo',
            ]);
            $doctor->specialties()->syncWithoutDetaching([$specialty->id]);
            foreach (range(0, 6) as $weekday) {
                Schedule::firstOrCreate(['doctor_id' => $doctor->id, 'weekday' => $weekday], [
                    'start_time' => '07:00', 'end_time' => '23:30', 'duration' => 30,
                ]);
            }
            $definitions = [['demo.medico', 'Andrea Demostración', 'Médico', $doctor->id, null],
                ['demo.recepcion', 'Recepción Demostración', 'Recepcionista', null, null]];
            foreach ([1, 2] as $number) {
                $patient = Patient::firstOrCreate(['identity' => 'DEMO-FLUJO-PAC-00'.$number], [
                    'first_name' => 'Paciente '.$number, 'last_name' => 'Demostración', 'birth_date' => '2000-01-0'.$number,
                    'sex' => $number === 1 ? 'Femenino' : 'Masculino', 'phone' => '7000001'.$number,
                    'email' => 'paciente'.$number.'.flujo@example.test', 'address' => 'Dirección ficticia de demostración', 'status' => 'Activo',
                ]);
                $definitions[] = ['demo.paciente'.$number, 'Paciente '.$number.' Demostración', 'Paciente', null, $patient->id];
            }
            $credentials = [];
            foreach ($definitions as [$username, $name, $role, $doctorId, $patientId]) {
                $email = $username.'@example.test';
                if (User::where('username', $username)->orWhere('email', $email)->exists()) {
                    throw new \RuntimeException('Ya existe una cuenta con el identificador '.$username.'. No se alteró su acceso.');
                }
                $password = Str::password(16, symbols: false);
                $user = new User(['username' => $username, 'name' => $name, 'email' => $email, 'role' => $role,
                    'birth_date' => '2000-01-01', 'terms_accepted_at' => $role === 'Paciente' ? now() : null, 'terms_version' => $role === 'Paciente' ? config('clinic.terms_version') : null, 'password' => $password, 'status' => 'Activo', 'doctor_id' => $doctorId, 'patient_id' => $patientId]);
                $user->forceFill(['email_verified_at' => now()])->save();
                $credentials[] = ['usuario' => $username, 'contrasena' => $password, 'rol' => $role];
            }
            if (! Storage::disk('local')->put($path, json_encode($credentials, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))) {
                throw new \RuntimeException('No se pudo guardar el acceso privado; se revierte la creación.');
            }

            return $credentials;
        });
        $this->info(count($credentials).' cuentas de demostración activas. Accesos guardados en storage/app/private/'.$path);
        $this->info('El médico Andrea Demostración atiende la especialidad Consulta de demostración. No se envió correo.');

        return self::SUCCESS;
    }
}
