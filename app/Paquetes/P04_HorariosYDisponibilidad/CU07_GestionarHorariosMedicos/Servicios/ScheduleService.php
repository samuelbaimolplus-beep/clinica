<?php

/** Valida y guarda turnos sin superposición; las citas afectadas conservan su estado. */

namespace App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Servicios;

use App\Models\AgendaAdjustment;
use App\Models\BookingRequest;
use App\Models\Doctor;
use App\Models\Schedule;
use App\Models\Unavailability;
use App\Models\User;
use App\Paquetes\P04_HorariosYDisponibilidad\Compartido\Servicios\ScheduleBlocks;
use App\Paquetes\P04_HorariosYDisponibilidad\Compartido\Servicios\ScheduleImpact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** CU07: turnos independientes, sin desplazar citas previamente formalizadas. */
class ScheduleService
{
    /** Coordina la validación y persistencia de esta operación; delega las reglas del dominio al servicio correspondiente. */
    public function save(array $data, ?int $id = null): Schedule
    {
        $minutes = (int) CarbonImmutable::parse($data['start_time'])->diffInMinutes(CarbonImmutable::parse($data['end_time']));
        if ($minutes < $data['duration']) {
            throw ValidationException::withMessages(['duration' => 'La consulta debe caber íntegramente dentro del turno.']);
        }

        return DB::transaction(function () use ($data, $id) {
            $this->lockActiveDoctor($data['doctor_id']);
            if (BookingRequest::where('doctor_id', $data['doctor_id'])->whereIn('status', ['held', 'processing'])->exists()) {
                throw ValidationException::withMessages(['schedule' => 'Hay pagos online retenidos. Concilie esas operaciones antes de cambiar el turno.']);
            }
            $schedule = $id ? Schedule::whereKey($id)->where('doctor_id', $data['doctor_id'])->lockForUpdate()->firstOrFail() : new Schedule;
            $conflict = Schedule::where('doctor_id', $data['doctor_id'])->where('weekday', $data['weekday'])
                ->when($id, fn ($q) => $q->whereKeyNot($id))
                ->where('start_time', '<', $data['end_time'])->where('end_time', '>', $data['start_time'])->orderBy('start_time')->first();
            if ($conflict) {
                $day = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'][(int) $conflict->weekday];
                $start = substr($conflict->start_time, 0, 5);
                $end = substr($conflict->end_time, 0, 5);
                throw ValidationException::withMessages(['schedule' => "El médico ya tiene un turno el {$day} de {$start} a {$end}. Modifique ese turno desde la semana de atención o elija una franja que no se cruce."]);
            }
            $schedule->fill($data)->save();
            foreach (AgendaAdjustment::where('doctor_id', $data['doctor_id'])->where('date', '>=', today()->toDateString())->where('status', 'active')
                ->where(fn ($query) => $query->where('kind', 'online_opening')->orWhere('date', today()->toDateString()))->get() as $adjustment) {
                if (! collect(app(ScheduleBlocks::class)->forDate($data['doctor_id'], $adjustment->date))
                    ->contains(fn ($block) => $block['start']->format('H:i:s') === $adjustment->start_time && $block['end']->format('H:i:s') === $adjustment->end_time)) {
                    throw ValidationException::withMessages(['schedule' => $adjustment->kind === 'online_opening'
                        ? 'El cambio invalidaría una habilitación online registrada. La V6 no permite retirar esa habilitación.'
                        : 'El cambio invalidaría bloqueos de hoy. Recepción debe liberarlos antes de modificar el turno.']);
                }
            }
            $schedule->setAttribute('affected_appointments', app(ScheduleImpact::class)->affected($data['doctor_id']));

            return $schedule;
        });
    }

    /** Registra una ausencia médica y devuelve las citas que requieren gestión. */
    public function unavailable(array $data, bool $preview = false): array
    {
        return DB::transaction(function () use ($data, $preview) {
            $this->lockActiveDoctor($data['doctor_id']);
            if (! $preview && BookingRequest::where('doctor_id', $data['doctor_id'])->whereIn('status', ['held', 'processing'])
                ->whereRaw('(date + start_time) < ?::timestamp AND (date + end_time) > ?::timestamp', [$data['ends_at'], $data['starts_at']])->exists()) {
                throw ValidationException::withMessages(['schedule' => 'El período contiene un pago online pendiente de conciliación.']);
            }
            $affected = app(ScheduleImpact::class)->overlapping($data['doctor_id'], $data['starts_at'], $data['ends_at']);

            return ['unavailability' => $preview ? null : Unavailability::create($data), 'affected' => $affected];
        });
    }

    /** Retira el bloqueo, conservando fechas, motivo original, actor e historial. */
    public function cancelUnavailability(int $id, string $reason, User $actor): array
    {
        return DB::transaction(function () use ($id, $reason, $actor) {
            $absence = Unavailability::findOrFail($id);
            Doctor::whereKey($absence->doctor_id)->lockForUpdate()->firstOrFail();
            $absence = Unavailability::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($absence->cancelled_at, 409, 'Esta ausencia ya fue retirada. Actualice la consulta.');
            $absence->fill(['cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancellation_reason' => $reason])->save();

            return ['unavailability' => $absence->fresh(), 'affected' => app(ScheduleImpact::class)->affected($absence->doctor_id)];
        });
    }

    /** Bloquea la fila del médico activo para serializar cambios concurrentes de disponibilidad. */
    private function lockActiveDoctor(int $id): void
    {
        $doctor = Doctor::whereKey($id)->lockForUpdate()->firstOrFail();
        if ($doctor->status !== 'Activo') {
            throw ValidationException::withMessages(['doctor_id' => 'El médico debe estar activo.']);
        }
    }
}
