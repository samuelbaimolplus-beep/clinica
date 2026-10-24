<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Schedule;
use App\Models\Specialty;
use App\Models\Unavailability;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    public function slots(int $doctorId, string $date, ?int $exclude = null): array
    {
        $day = CarbonImmutable::parse($date);
        $schedule = Schedule::where('doctor_id', $doctorId)->where('weekday', $day->dayOfWeek)->first();
        if (! $schedule || Doctor::findOrFail($doctorId)->status !== 'Activo') {
            return [];
        }
        $appointments = Appointment::where('doctor_id', $doctorId)->where('date', $date)->whereIn('status', Appointment::ACTIVE)->when($exclude, fn ($q) => $q->whereKeyNot($exclude))->get();
        $blocks = Unavailability::where('doctor_id', $doctorId)->where('starts_at', '<', $day->addDay())->where('ends_at', '>', $day)->get();
        $end = CarbonImmutable::parse($date.' '.$schedule->end_time);
        $result = [];
        for ($start = CarbonImmutable::parse($date.' '.$schedule->start_time); $start->addMinutes($schedule->duration) <= $end; $start = $start->addMinutes($schedule->duration)) {
            $finish = $start->addMinutes($schedule->duration);
            $busy = $appointments->first(fn ($a) => $a->start_time < $finish->format('H:i:s') && $a->end_time > $start->format('H:i:s'));
            $block = $blocks->first(fn ($b) => CarbonImmutable::parse($b->starts_at) < $finish && CarbonImmutable::parse($b->ends_at) > $start);
            $state = $block ? 'Bloqueado' : ($busy ? 'Ocupado' : ($start < now() ? 'Pasado' : 'Disponible'));
            $result[] = ['start' => $start->format('H:i'), 'end' => $finish->format('H:i'), 'status' => $state, 'detail' => $block?->reason ?? ($busy ? 'CIT-'.str_pad($busy->id, 4, '0', STR_PAD_LEFT) : '')];
        }

        return $result;
    }

    public function book(array $data, User $actor, ?int $id = null): Appointment
    {
        return DB::transaction(function () use ($data, $actor, $id) {
            // Every booking locks patient then doctor; schedule and absence writers lock the doctor too.
            $original = $id ? Appointment::findOrFail($id) : null;
            $patient = Patient::whereKey($original?->patient_id ?? $data['patient_id'])->lockForUpdate()->firstOrFail();
            $doctor = Doctor::whereKey($data['doctor_id'])->lockForUpdate()->firstOrFail();
            $appointment = $id ? Appointment::whereKey($id)->lockForUpdate()->firstOrFail() : new Appointment;
            if ($id && ! in_array($appointment->status, Appointment::ACTIVE)) {
                $this->fail('Solo se pueden reprogramar citas vigentes.');
            }
            $specialtyId = $id ? $appointment->specialty_id : $data['specialty_id'];
            $specialty = Specialty::findOrFail($specialtyId);
            if ($patient->status !== 'Activo' || $doctor->status !== 'Activo' || $specialty->status !== 'Activo') {
                $this->fail('El paciente, médico y especialidad deben estar activos.');
            }
            if (! $doctor->specialties()->whereKey($specialtyId)->exists()) {
                $this->fail('El médico no pertenece a la especialidad seleccionada.');
            }
            $slot = collect($this->slots($doctor->id, $data['date'], $id))->firstWhere('start', $data['start_time']);
            if (! $slot || $slot['status'] !== 'Disponible') {
                $this->fail('El horario ya no está disponible. Consulte los bloques libres.');
            }
            if (Appointment::where('patient_id', $patient->id)->where('date', $data['date'])->whereIn('status', Appointment::ACTIVE)->when($id, fn ($q) => $q->whereKeyNot($id))->where('start_time', '<', $slot['end'])->where('end_time', '>', $slot['start'])->exists()) {
                $this->fail('El paciente ya tiene una cita que se superpone con este horario.');
            }
            $before = $id ? $appointment->only(['doctor_id', 'date', 'start_time', 'end_time']) : null;
            $appointment->fill(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'specialty_id' => $specialtyId, 'date' => $data['date'], 'start_time' => $slot['start'], 'end_time' => $slot['end']]);
            if (! $id) {
                $appointment->fill(['status' => 'Programada', 'reason' => $data['reason'] ?? null, 'created_by' => $actor->id]);
            }
            if ($id && $appointment->attendance_at) {
                $this->fail('No se puede reprogramar una cita cuya asistencia ya fue registrada.');
            }
            $appointment->save();
            $this->event($appointment, $actor, $id ? 'Reprogramación' : 'Creación', ['before' => $before, 'after' => $appointment->only(['doctor_id', 'date', 'start_time', 'end_time'])]);

            return $appointment->load('patient', 'doctor', 'specialty', 'events');
        }, 3);
    }

    public function transition(int $id, string $action, User $actor, ?string $reason = null): Appointment
    {
        return DB::transaction(function () use ($id, $action, $actor, $reason) {
            $a = Appointment::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($actor->role === 'Médico' && $a->doctor_id !== $actor->doctor_id) {
                abort(403, 'Solo puede atender citas de su propia agenda.');
            }
            if (! in_array($a->status, Appointment::ACTIVE)) {
                $this->fail('La cita ya no está vigente.');
            }
            $today = now()->toDateString();
            if (in_array($action, ['attendance', 'absent', 'attend']) && $a->date !== $today) {
                $this->fail('Esta operación solo está disponible para las citas de hoy.');
            }
            $before = $a->status;
            switch ($action) {
                case 'cancel': $a->status = 'Cancelada';
                    $a->cancellation_reason = $reason;
                    break;
                case 'confirm':
                    if ($a->status !== 'Programada') {
                        $this->fail('Solo se pueden confirmar citas programadas.');
                    }
                    $a->status = 'Confirmada';
                    break;
                case 'attendance':
                    if ($a->attendance_at) {
                        $this->fail('La asistencia ya fue registrada.');
                    }
                    $a->attendance_at = now();
                    break;
                case 'absent':
                    if ($a->attendance_at) {
                        $this->fail('El paciente ya registró asistencia.');
                    }
                    if (CarbonImmutable::parse($a->date.' '.$a->end_time) > now()) {
                        $this->fail('RN16: el horario previsto aún no ha finalizado.');
                    }
                    $a->status = 'Inasistencia';
                    break;
                case 'attend': $a->status = 'Atendida';
                    break;
            }
            $a->save();
            $this->event($a, $actor, $action, ['before' => $before, 'after' => $a->status, 'reason' => $reason, 'attendance_at' => $a->attendance_at]);

            return $a->load('patient', 'doctor', 'specialty', 'events');
        });
    }

    public function event(Appointment $a, User $actor, string $action, array $details): void
    {
        $a->events()->create(['user_id' => $actor->id, 'action' => $action, 'details' => $details]);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['appointment' => $message]);
    }
}
