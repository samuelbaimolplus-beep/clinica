<?php

/** Localiza citas que necesitan intervención de Recepción tras cambiar disponibilidad o habilitaciones. */

namespace App\Paquetes\P04_HorariosYDisponibilidad\Compartido\Servicios;

use App\Models\Appointment;
use App\Models\Unavailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** CU07 y CU17: el conflicto se calcula con la configuración vigente, sin mutar citas. */
class ScheduleImpact
{
    /** Calcula las citas afectadas por el cambio conservando su programación y estado. */
    public function affected(?int $doctorId = null): Collection
    {
        $appointments = Appointment::whereIn('status', Appointment::ACTIVE)
            ->when($doctorId, fn ($q) => $q->where('doctor_id', $doctorId))->orderBy('date')->orderBy('start_time')->get();
        $absences = Unavailability::active()->whereIn('doctor_id', $appointments->pluck('doctor_id')->unique())->get()->groupBy('doctor_id');
        $ranges = [];

        return $appointments->map(function ($appointment) use (&$ranges, $absences) {
            $key = $appointment->doctor_id.':'.$appointment->date;
            $ranges[$key] ??= app(ScheduleBlocks::class)->forDate($appointment->doctor_id, $appointment->date);
            $start = CarbonImmutable::parse($appointment->date.' '.$appointment->start_time);
            $end = CarbonImmutable::parse($appointment->date.' '.$appointment->end_time);
            $reasons = [];
            $origins = [];
            if ($appointment->date >= today()->toDateString() && ! collect($ranges[$key])->contains(fn ($range) => $range['start']->equalTo($start) && $range['end']->equalTo($end))) {
                $reasons[] = 'Horario o duración fuera de la configuración vigente';
                $origins[] = ['entity' => 'DoctorSchedule', 'doctor_id' => $appointment->doctor_id,
                    'schedule_ids' => collect($ranges[$key])->pluck('schedule_id')->unique()->values()->all()];
            }
            if (($absences[$appointment->doctor_id] ?? collect())->contains(fn ($absence) => CarbonImmutable::parse($absence->starts_at) < $end && CarbonImmutable::parse($absence->ends_at) > $start)) {
                $reasons[] = 'Indisponibilidad médica';
                foreach ($absences[$appointment->doctor_id] as $absence) {
                    if (CarbonImmutable::parse($absence->starts_at) < $end && CarbonImmutable::parse($absence->ends_at) > $start) {
                        $origins[] = ['entity' => 'Unavailability', 'id' => $absence->id];
                    }
                }
            }

            return $appointment->only(['id', 'doctor_id', 'specialty_id', 'date', 'start_time', 'end_time', 'status']) + ['reasons' => $reasons, 'origins' => $origins];
        })->filter(fn ($item) => count($item['reasons']) > 0)->values();
    }

    /** Una cita pendiente no deja de estar afectada porque ya pasó su hora de inicio. */
    public function overlapping(int $doctorId, string $startsAt, string $endsAt): Collection
    {
        return Appointment::with(['patient:id,first_name,last_name,phone', 'doctor:id,first_name,last_name', 'specialty:id,name'])
            ->where('doctor_id', $doctorId)->whereIn('status', Appointment::ACTIVE)
            ->whereRaw('(date + start_time) < ?::timestamp AND (date + end_time) > ?::timestamp', [$endsAt, $startsAt])
            ->orderBy('date')->orderBy('start_time')->orderBy('id')->get();
    }
}
