<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\FranjaClinica;
use App\Models\Horario;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Pago;
use App\Models\Usuario;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (config('database.connections.pgsql.database') !== 'clinica_vida_salud_browser_test'
    || DB::selectOne('select current_database() as database_name')->database_name !== 'clinica_vida_salud_browser_test') {
    throw new RuntimeException('Solo se permite la base exclusiva de pruebas.');
}
$run = 'web'.date('mdHis').random_int(100, 999);
$password = 'NavegadorPrueba2026!';
$data = DB::transaction(function () use ($run, $password) {
    $specialty = Especialidad::create(['name' => 'Consulta '.$run, 'status' => 'Activo', 'fee_minor' => 10000]);
    $doctor = Medico::create(['registration' => $run, 'first_name' => 'Medica', 'last_name' => $run, 'email' => $run.'@doctor.example.test', 'phone' => '70000000', 'status' => 'Activo']);
    $doctor->specialties()->attach($specialty->id);
    foreach (range(0, 6) as $weekday) {
        Horario::create(['doctor_id' => $doctor->id, 'weekday' => $weekday, 'start_time' => '00:00', 'end_time' => '23:45', 'duration' => 15]);
    }
    $openingDoctor = Medico::create(['registration' => $run.'online', 'first_name' => 'Cupos', 'last_name' => $run, 'email' => $run.'@opening.example.test', 'phone' => '70000006', 'status' => 'Activo']);
    $openingDoctor->specialties()->attach($specialty->id);
    $start = now()->addMinutes(5)->startOfMinute();
    $end = $start->copy()->addMinutes(90);
    if ($start->isToday()) {
        Horario::create(['doctor_id' => $openingDoctor->id, 'weekday' => today()->dayOfWeek, 'start_time' => $start->format('H:i'), 'end_time' => $end->isToday() ? $end->format('H:i') : '23:59', 'duration' => 5]);
    }
    $patient = Paciente::create(['identity' => $run, 'first_name' => 'Paciente', 'last_name' => $run, 'phone' => '70000001', 'birth_date' => '2000-01-01', 'sex' => 'Femenino', 'status' => 'Activo']);
    $child = Paciente::create(['identity' => $run.'-n', 'first_name' => 'Menor', 'last_name' => $run, 'phone' => '70000002', 'birth_date' => '2018-01-01', 'sex' => 'Femenino', 'status' => 'Activo']);
    $users = [];
    foreach (['super' => 'Superadmin', 'admin' => 'Administrador', 'reception' => 'Recepcionista', 'doctor' => 'Médico', 'patient' => 'Paciente'] as $key => $role) {
        $user = new Usuario(['username' => $run.'.'.$key, 'name' => $key.' '.$run, 'email' => $key.'.'.$run.'@example.test', 'password' => $password, 'role' => $role, 'status' => 'Activo', 'birth_date' => '1990-01-01',
            'doctor_id' => $key === 'doctor' ? $doctor->id : null, 'patient_id' => $key === 'patient' ? $patient->id : null, 'terms_accepted_at' => $key === 'patient' ? now() : null, 'terms_version' => $key === 'patient' ? config('clinic.terms_version') : null]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $users[$key] = ['id' => $user->id, 'username' => $user->username];
    }
    $appointments = [];
    foreach (['arrival' => '08:00', 'absent' => '08:15', 'consult' => '08:30', 'followup' => '08:45', 'reschedule' => '09:00', 'cancel' => '09:15', 'impact' => '10:00', 'superconsult' => '09:30'] as $key => $time) {
        $date = in_array($key, ['reschedule', 'cancel', 'impact']) ? today()->addDay()->toDateString() : today()->toDateString();
        $end = substr($time, 0, 3).str_pad((int) substr($time, 3) + 15, 2, '0', STR_PAD_LEFT);
        if ($time === '08:45') {
            $end = '09:00';
        }
        $payment = Pago::create(['method' => 'cash', 'amount_minor' => 10000, 'status' => 'confirmed', 'reference' => $run.'-'.$key, 'confirmed_at' => now()]);
        $a = Cita::create(['patient_id' => $key === 'arrival' ? $child->id : $patient->id, 'doctor_id' => $doctor->id, 'specialty_id' => $specialty->id, 'created_by' => $users['reception']['id'], 'date' => $date, 'start_time' => $time, 'end_time' => $end,
            'clinical_slot_id' => FranjaClinica::identificar($doctor->id, $date, $time, $end), 'modality' => 'presential', 'status' => $key === 'consult' ? 'Confirmada' : ($key === 'followup' ? 'Atendida' : 'Programada'), 'payment_id' => $payment->id,
            'attendance_at' => in_array($key, ['consult', 'followup']) ? now() : null, 'attended_at' => $key === 'followup' ? now() : null, 'followup_suggested_at' => $key === 'followup' ? today()->addDay()->setTime(9, 45) : null]);
        $appointments[$key] = $a->id;
    }

    return ['run' => $run, 'password' => $password, 'users' => $users, 'specialty' => ['id' => $specialty->id, 'name' => $specialty->name], 'doctor' => ['id' => $doctor->id, 'name' => $doctor->first_name.' '.$doctor->last_name],
        'openingDoctor' => ['id' => $openingDoctor->id, 'name' => $openingDoctor->first_name.' '.$openingDoctor->last_name],
        'patient' => ['id' => $patient->id, 'name' => $patient->first_name.' '.$patient->last_name], 'child' => ['id' => $child->id, 'name' => $child->first_name.' '.$child->last_name], 'appointments' => $appointments, 'today' => today()->toDateString(), 'tomorrow' => today()->addDay()->toDateString()];
});
file_put_contents(storage_path('app/private/browser-real-fixture.json'), json_encode($data,JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Datos ficticios preparados exclusivamente en clinica_vida_salud_browser_test.\n";
