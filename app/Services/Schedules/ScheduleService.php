<?php

namespace App\Services\Schedules;

use App\Models\AgendaAdjustment;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Schedule;
use App\Models\Unavailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** CU07: turnos independientes, sin desplazar citas previamente formalizadas. */
class ScheduleService
{
    public function save(array $data, ?int $id = null): Schedule
    {
        $minutes = (int) CarbonImmutable::parse($data['start_time'])->diffInMinutes(CarbonImmutable::parse($data['end_time']));
        if ($minutes < $data['duration'] || $minutes % $data['duration'] !== 0) {
            throw ValidationException::withMessages(['duration' => 'La duración debe dividir el turno en consultas completas, sin minutos sobrantes.']);
        }

        return DB::transaction(function () use ($data, $id) {
            $this->lockActiveDoctor($data['doctor_id']);
            $schedule = $id ? Schedule::whereKey($id)->where('doctor_id', $data['doctor_id'])->lockForUpdate()->firstOrFail() : new Schedule;
            if (Schedule::where('doctor_id', $data['doctor_id'])->where('weekday', $data['weekday'])
                ->when($id, fn ($q) => $q->whereKeyNot($id))
                ->where('start_time', '<', $data['end_time'])->where('end_time', '>', $data['start_time'])->exists()) {
                throw ValidationException::withMessages(['schedule' => 'El turno se superpone con otro horario del médico.']);
            }
            $schedule->fill($data)->save();
            foreach (AgendaAdjustment::where('doctor_id', $data['doctor_id'])->where('date', '>=', today()->toDateString())->where('status', 'active')->get() as $adjustment) {
                if (! collect(app(ScheduleBlocks::class)->forDate($data['doctor_id'], $adjustment->date))
                    ->contains(fn ($block) => $block['start']->format('H:i:s') === $adjustment->start_time && $block['end']->format('H:i:s') === $adjustment->end_time)) {
                    throw ValidationException::withMessages(['schedule' => 'El cambio invalidaría movimientos activos de Recepción. Libérelos antes de modificar el turno.']);
                }
            }
            $schedule->setAttribute('affected_appointments', app(ScheduleImpact::class)->affected($data['doctor_id']));

            return $schedule;
        });
    }

    public function unavailable(array $data, bool $preview = false): array
    {
        return DB::transaction(function () use ($data, $preview) {
            $this->lockActiveDoctor($data['doctor_id']);
            $affected = Appointment::with('patient', 'doctor', 'specialty')->where('doctor_id', $data['doctor_id'])
                ->whereIn('status', Appointment::ACTIVE)->whereRaw('(date + start_time) >= ?::timestamp', [now()->format('Y-m-d H:i:s')])
                ->whereRaw('(date + start_time) < ?::timestamp AND (date + end_time) > ?::timestamp', [$data['ends_at'], $data['starts_at']])->get();

            return ['unavailability' => $preview ? null : Unavailability::create($data), 'affected' => $affected];
        });
    }

    private function lockActiveDoctor(int $id): void
    {
        $doctor = Doctor::whereKey($id)->lockForUpdate()->firstOrFail();
        if ($doctor->status !== 'Activo') {
            throw ValidationException::withMessages(['doctor_id' => 'El médico debe estar activo.']);
        }
    }
}
