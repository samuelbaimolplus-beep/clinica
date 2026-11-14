<?php

/** Prepara exclusivamente identidades ficticias locales y conserva contraseñas de demostración existentes. */

namespace App\Console\Commands;

use App\Models\Especialidad;
use App\Models\Horario;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Usuario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CrearCuentasDemostracion extends Command
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
        $ruta = 'accesos-demostracion.json';
        if (Storage::disk('local')->exists($ruta)) {
            // Solo identidades ficticias conocidas; no se inventan fechas de usuarios reales.
            DB::transaction(function () {
                foreach (['demo.medico', 'demo.recepcion', 'demo.paciente1', 'demo.paciente2'] as $username) {
                    $usuario = Usuario::where('username', $username)->where('email', $username.'@example.test')->first();
                    if (! $usuario || ! str_contains($usuario->name, 'Demostración')) {
                        continue;
                    }
                    if ($usuario->role === 'Paciente' && ! str_starts_with($usuario->patient?->identity ?? '', 'DEMO-FLUJO-PAC-')) {
                        continue;
                    }
                    $usuario->birth_date = $usuario->role === 'Paciente' ? $usuario->patient->birth_date : '2000-01-01';
                    if ($usuario->role === 'Paciente') {
                        $usuario->terms_accepted_at ??= now();
                        $usuario->terms_version = config('clinic.terms_version');
                    }
                    $usuario->save();
                }
            });
            $this->info('Las cuentas ya se prepararon. Sus accesos están en storage/app/private/'.$ruta);

            return self::SUCCESS;
        }

        $credenciales = DB::transaction(function () use ($ruta) {
            $specialty = Especialidad::firstOrCreate(['name' => 'Consulta de demostración'], [
                'description' => 'Especialidad ficticia para recorrer el sistema local.', 'status' => 'Activo', 'fee_minor' => 10000,
            ]);
            $medico = Medico::firstOrCreate(['registration' => 'DEMO-FLUJO-MED-001'], [
                'first_name' => 'Andrea', 'last_name' => 'Demostración', 'email' => 'medico.flujo@example.test',
                'phone' => '70000001', 'status' => 'Activo',
            ]);
            $medico->specialties()->syncWithoutDetaching([$specialty->id]);
            foreach (range(0, 6) as $weekday) {
                Horario::firstOrCreate(['doctor_id' => $medico->id, 'weekday' => $weekday], [
                    'start_time' => '07:00', 'end_time' => '23:30', 'duration' => 30,
                ]);
            }
            $definitions = [['demo.medico', 'Andrea Demostración', 'Médico', $medico->id, null],
                ['demo.recepcion', 'Recepción Demostración', 'Recepcionista', null, null]];
            foreach ([1, 2] as $number) {
                $paciente = Paciente::firstOrCreate(['identity' => 'DEMO-FLUJO-PAC-00'.$number], [
                    'first_name' => 'Paciente '.$number, 'last_name' => 'Demostración', 'birth_date' => '2000-01-0'.$number,
                    'sex' => $number === 1 ? 'Femenino' : 'Masculino', 'phone' => '7000001'.$number,
                    'email' => 'paciente'.$number.'.flujo@example.test', 'address' => 'Dirección ficticia de demostración', 'status' => 'Activo',
                ]);
                $definitions[] = ['demo.paciente'.$number, 'Paciente '.$number.' Demostración', 'Paciente', null, $paciente->id];
            }
            $credenciales = [];
            foreach ($definitions as [$username, $nombre, $rol, $doctorId, $patientId]) {
                $correo = $username.'@example.test';
                if (Usuario::where('username', $username)->orWhere('email', $correo)->exists()) {
                    throw new \RuntimeException('Ya existe una cuenta con el identificador '.$username.'. No se alteró su acceso.');
                }
                $contrasena = Str::password(16, symbols: false);
                $usuario = new Usuario(['username' => $username, 'name' => $nombre, 'email' => $correo, 'role' => $rol,
                    'birth_date' => '2000-01-01', 'terms_accepted_at' => $rol === 'Paciente' ? now() : null, 'terms_version' => $rol === 'Paciente' ? config('clinic.terms_version') : null, 'password' => $contrasena, 'status' => 'Activo', 'doctor_id' => $doctorId, 'patient_id' => $patientId]);
                $usuario->forceFill(['email_verified_at' => now()])->save();
                $credenciales[] = ['usuario' => $username, 'contrasena' => $contrasena, 'rol' => $rol];
            }
            if (! Storage::disk('local')->put($ruta, json_encode($credenciales, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))) {
                throw new \RuntimeException('No se pudo guardar el acceso privado; se revierte la creación.');
            }

            return $credenciales;
        });
        $this->info(count($credenciales).' cuentas de demostración activas. Accesos guardados en storage/app/private/'.$ruta);
        $this->info('El médico Andrea Demostración atiende la especialidad Consulta de demostración. No se envió correo.');

        return self::SUCCESS;
    }
}
