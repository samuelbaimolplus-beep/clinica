<?php

/** Datos ficticios para desarrollo o pruebas; no representan personas reales ni sustituyen el alta operativa. */

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Schedule;
use App\Models\Specialty;
use App\Models\Unavailability;
use App\Models\User;
use App\Paquetes\P05_CitasMedicas\Compartido\Servicios\AppointmentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    /** Carga los datos iniciales o ficticios definidos por este seeder. */
    public function run(): void
    {
        $credentials = [];
        DB::transaction(function () use (&$credentials) {
            DB::select('SELECT pg_advisory_xact_lock(?)', [20261024]);
            $admin = User::where('role', 'Administrador')->where('status', 'Activo')->firstOrFail();
            $specialties = collect([
                ['Medicina General', 'Atención primaria y controles generales.'],
                ['Pediatría', 'Atención y seguimiento infantil.'],
                ['Ginecología', 'Controles de salud de la mujer.'],
                ['Odontología', 'Prevención y salud bucal.'],
            ])->map(fn ($s) => Specialty::firstOrCreate(['name' => $s[0]], ['description' => $s[1], 'status' => 'Activo', 'fee_minor' => 10000]));

            $doctors = collect([
                ['Carlos', 'Rojas Vaca', [0]], ['Lucía', 'Fernández Soliz', [1]],
                ['Ana', 'Mendoza Ribera', [2]], ['Jorge', 'Peña Arce', [3]],
                ['Marcela', 'Justiniano Egüez', [0, 1]],
            ])->map(function ($d, $i) use ($specialties) {
                $doctor = Doctor::firstOrCreate(['registration' => 'DEMO-RP-'.($i + 1)], [
                    'first_name' => $d[0], 'last_name' => $d[1], 'phone' => '7000000'.($i + 1),
                    'email' => 'medico'.($i + 1).'@demo.example', 'status' => 'Activo',
                ]);
                $doctor->specialties()->syncWithoutDetaching(collect($d[2])->map(fn ($index) => $specialties[$index]->id)->all());
                foreach (range(1, 6) as $day) {
                    Schedule::firstOrCreate(['doctor_id' => $doctor->id, 'weekday' => $day], [
                        'start_time' => '08:00', 'end_time' => '12:00', 'duration' => 30,
                    ]);
                }

                return $doctor;
            });

            $patients = collect([
                ['María', 'Suárez Vaca', '1990-03-14', 'Femenino'],
                ['Juan', 'Pérez Roca', '1985-07-02', 'Masculino'],
                ['Sofía', 'Gutiérrez Añez', '2018-11-20', 'Femenino'],
                ['Lidia', 'Choque Mamani', '1978-01-30', 'Femenino'],
                ['Diego', 'Salvatierra Lara', '1996-09-09', 'Masculino'],
                ['Camila', 'Vargas Ríos', '1993-05-18', 'Femenino'],
                ['Mateo', 'Flores Arce', '2015-08-11', 'Masculino'],
                ['Valeria', 'Molina Paz', '1988-12-03', 'Femenino'],
                ['Andrés', 'Roca Suárez', '1970-06-25', 'Masculino'],
                ['Daniela', 'Quispe López', '2002-02-14', 'Femenino'],
            ])->map(fn ($p, $i) => Patient::firstOrCreate(['identity' => 'DEMO-CI-'.str_pad($i + 1, 3, '0', STR_PAD_LEFT)], [
                'first_name' => $p[0], 'last_name' => $p[1], 'birth_date' => $p[2], 'sex' => $p[3],
                'phone' => '710000'.str_pad($i + 1, 2, '0', STR_PAD_LEFT), 'email' => 'paciente'.($i + 1).'@demo.example',
                'address' => 'Dirección ficticia de demostración, Santa Cruz', 'status' => 'Activo',
            ]));

            $account = function (string $email, string $name, string $role, ?int $doctorId = null) use (&$credentials) {
                $user = User::where('email', $email)->first();
                if (! $user) {
                    $password = Str::password(20, symbols: false);
                    $user = User::create(['birth_date' => '1990-01-01', 'name' => $name, 'email' => $email, 'password' => $password, 'role' => $role, 'doctor_id' => $doctorId, 'status' => 'Activo']);
                    $credentials[] = "Rol: {$role}\nCorreo: {$email}\nContraseña: {$password}\n";
                }

                return $user;
            };
            $reception = $account('recepcion@demo.example', 'Rosa Quispe (demo)', 'Recepcionista');
            foreach ($doctors as $i => $doctor) {
                if (! User::where('doctor_id', $doctor->id)->exists()) {
                    $account('medico'.($i + 1).'@demo.example', $doctor->first_name.' '.$doctor->last_name.' (demo)', 'Médico', $doctor->id);
                }
            }

            $service = app(AppointmentService::class);
            $future = today()->addDay();
            while ($future->isSunday()) {
                $future->addDay();
            }

            // Future appointments go through the same availability rules as the application.
            foreach (range(0, 9) as $i) {
                $reason = '[DEMO-'.str_pad($i + 1, 2, '0', STR_PAD_LEFT).'] '.['Control general', 'Consulta de seguimiento', 'Control preventivo', 'Revisión de rutina', 'Consulta inicial'][$i % 5];
                if (Appointment::where('reason', 'like', $reason.'%')->exists()) {
                    continue;
                }
                $doctor = $doctors[$i % 5];
                $date = $future->copy()->addDays(intdiv($i, 5));
                while ($date->isSunday()) {
                    $date->addDay();
                }
                $slot = collect($service->slots($doctor->id, $date->toDateString()))->firstWhere('status', 'Disponible');
                if (! $slot) {
                    continue;
                }
                $specialty = $doctor->specialties()->where('status', 'Activo')->firstOrFail();
                if (! $specialty->fee_minor) {
                    continue;
                } // No inventar tarifas para registros preexistentes.
                $a = $service->book(['patient_id' => $patients[$i]->id, 'doctor_id' => $doctor->id,
                    'specialty_id' => $specialty->id, 'idempotency_key' => (string) Str::uuid(),
                    'payment_method' => 'cash', 'payment_amount_minor' => $specialty->fee_minor, 'payment_confirmed' => true,
                    'date' => $date->toDateString(), 'start_time' => $slot['start'], 'reason' => $reason.' · Cobro ficticio de demostración'], $reception);
                if ($i === 9) {
                    $newSlot = collect($service->slots($doctor->id, $date->toDateString()))->firstWhere('status', 'Disponible');
                    if ($newSlot) {
                        $service->book(['doctor_id' => $doctor->id, 'date' => $date->toDateString(), 'start_time' => $newSlot['start'], 'cause' => 'clinic', 'patient_accepted' => true, 'change_reason' => 'Cambio ficticio acordado para demostración'], $reception, $a->id);
                    }
                }
            }

            // Synthetic completed records exercise history and report screens without medical data.
            $historyDate = today()->isSunday() ? today()->subDay() : today();
            foreach (range(0, 5) as $i) {
                $reason = '[DEMO-HIST-'.($i + 1).'] Consulta ficticia de demostración';
                if (Appointment::where('reason', $reason)->exists()) {
                    continue;
                }
                $doctor = $doctors[$i % 5];
                $start = $historyDate->copy()->setTime(9 + intdiv($i, 5), 0);
                if ($start->copy()->addMinutes(30)->isFuture()) {
                    $start->subDay();
                    while ($start->isSunday()) {
                        $start->subDay();
                    }
                }
                $status = ['Atendida', 'Cancelada', 'Inasistencia'][$i % 3];
                $a = Appointment::create(['patient_id' => $patients[$i]->id, 'doctor_id' => $doctor->id,
                    'specialty_id' => $doctor->specialties()->firstOrFail()->id, 'date' => $start->toDateString(),
                    'start_time' => $start->format('H:i'), 'end_time' => $start->copy()->addMinutes(30)->format('H:i'),
                    'status' => $status, 'reason' => $reason, 'created_by' => $admin->id,
                    'attendance_at' => $status === 'Atendida' ? $start->copy()->subMinutes(5) : null,
                    'cancellation_reason' => $status === 'Cancelada' ? 'Cancelación ficticia: cambio de planes del paciente.' : null,
                ]);
                $service->event($a, $admin, 'Datos de demostración', ['after' => $status, 'reason' => 'Registro completamente ficticio para probar el sistema.']);
            }

            $affected = Appointment::where('reason', 'like', '[DEMO-01]%')->first();
            if ($affected) {
                Unavailability::firstOrCreate(['doctor_id' => $affected->doctor_id, 'reason' => '[DEMO] Permiso para mostrar citas afectadas'], [
                    'starts_at' => $affected->date.' '.$affected->start_time,
                    'ends_at' => $affected->date.' '.$affected->end_time,
                ]);
            }
        });

        if ($credentials && ! Storage::disk('local')->append('accesos-demo.txt', "Cuentas ficticias de demostración\n\n".implode("\n", $credentials))) {
            throw new \RuntimeException('No se pudo guardar el archivo privado de accesos demo.');
        }
        $this->command?->info('Datos ficticios cargados sin borrar registros. Accesos nuevos: storage/app/private/accesos-demo.txt');
    }
}
