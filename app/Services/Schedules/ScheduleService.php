<?php

namespace App\Services\Schedules;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Schedule;
use App\Models\Unavailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** CU07 parcial: una franja por día y médico, sin distribución de cupos ni reservas. */
class ScheduleService
{
    public function save(array $data): Schedule
    {
        if (CarbonImmutable::parse($data['start_time'])->diffInMinutes(CarbonImmutable::parse($data['end_time'])) < $data['duration']) {
            throw ValidationException::withMessages(['duration' => 'La duración no puede superar el intervalo de atención.']);
        }

        return DB::transaction(function () use ($data) {
            $this->lockActiveDoctor($data['doctor_id']);
            $future = Appointment::where('doctor_id', $data['doctor_id'])->where('date', '>=', today()->toDateString())
                ->whereIn('status', Appointment::ACTIVE)->whereRaw('EXTRACT(DOW FROM date) = ?', [$data['weekday']])->get();
            foreach ($future as $appointment) {
                $offset = CarbonImmutable::parse($data['start_time'])->diffInMinutes(CarbonImmutable::parse($appointment->start_time), false);
                $duration = CarbonImmutable::parse($appointment->start_time)->diffInMinutes(CarbonImmutable::parse($appointment->end_time));
                if ($appointment->start_time < $data['start_time'].':00' || $appointment->end_time > $data['end_time'].':00' || $duration !== (float) $data['duration'] || $offset < 0 || (int) $offset % (int) $data['duration'] !== 0) {
                    throw ValidationException::withMessages(['schedule' => 'El cambio afecta citas vigentes. Reprográmelas o cancélelas antes de modificar este horario.']);
                }
            }

            return Schedule::updateOrCreate(['doctor_id' => $data['doctor_id'], 'weekday' => $data['weekday']], $data);
        });
    }

    public function unavailable(array $data, bool $preview = false): array
    {
        return DB::transaction(function () use ($data, $preview) {
            $this->lockActiveDoctor($data['doctor_id']);
            $affected = Appointment::with('patient', 'doctor', 'specialty')->where('doctor_id', $data['doctor_id'])
                ->whereIn('status', Appointment::ACTIVE)
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
