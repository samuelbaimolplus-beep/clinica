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
    public function guardar(array $datos, ?int $id = null): Schedule
    {
        $minutos = (int) CarbonImmutable::parse($datos['start_time'])->diffInMinutes(CarbonImmutable::parse($datos['end_time']));
        if ($minutos < $datos['duration']) {
            throw ValidationException::withMessages(['duration' => 'La consulta debe caber íntegramente dentro del turno.']);
        }

        return DB::transaction(function () use ($datos, $id) {
            $this->bloquearMedicoActivo($datos['doctor_id']);
            if (BookingRequest::where('doctor_id', $datos['doctor_id'])->whereIn('status', ['held', 'processing'])->exists()) {
                throw ValidationException::withMessages(['schedule' => 'Hay pagos online retenidos. Concilie esas operaciones antes de cambiar el turno.']);
            }
            $horario = $id ? Schedule::whereKey($id)->where('doctor_id', $datos['doctor_id'])->lockForUpdate()->firstOrFail() : new Schedule;
            $conflicto = Schedule::where('doctor_id', $datos['doctor_id'])->where('weekday', $datos['weekday'])
                ->when($id, fn ($consulta) => $consulta->whereKeyNot($id))
                ->where('start_time', '<', $datos['end_time'])->where('end_time', '>', $datos['start_time'])->orderBy('start_time')->first();
            if ($conflicto) {
                $dia = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'][(int) $conflicto->weekday];
                $inicio = substr($conflicto->start_time, 0, 5);
                $fin = substr($conflicto->end_time, 0, 5);
                throw ValidationException::withMessages(['schedule' => "El médico ya tiene un turno el {$dia} de {$inicio} a {$fin}. Modifique ese turno desde la semana de atención o elija una franja que no se cruce."]);
            }
            $horario->fill($datos)->save();
            foreach (AgendaAdjustment::where('doctor_id', $datos['doctor_id'])->where('date', '>=', today()->toDateString())->where('status', 'active')
                ->where(fn ($consultaDatos) => $consultaDatos->where('kind', 'online_opening')->orWhere('date', today()->toDateString()))->get() as $ajuste) {
                if (! collect(app(ScheduleBlocks::class)->franjasParaFecha($datos['doctor_id'], $ajuste->date))
                    ->contains(fn ($bloque) => $bloque['start']->format('H:i:s') === $ajuste->start_time && $bloque['end']->format('H:i:s') === $ajuste->end_time)) {
                    throw ValidationException::withMessages(['schedule' => $ajuste->kind === 'online_opening'
                        ? 'El cambio invalidaría una habilitación online registrada. La V6 no permite retirar esa habilitación.'
                        : 'El cambio invalidaría bloqueos de hoy. Recepción debe liberarlos antes de modificar el turno.']);
                }
            }
            $horario->setAttribute('affected_appointments', app(ScheduleImpact::class)->citasAfectadas($datos['doctor_id']));

            return $horario;
        });
    }

    /** Registra una ausencia médica y devuelve las citas que requieren gestión. */
    public function registrarAusencia(array $datos, bool $soloVistaPrevia = false): array
    {
        return DB::transaction(function () use ($datos, $soloVistaPrevia) {
            $this->bloquearMedicoActivo($datos['doctor_id']);
            if (! $soloVistaPrevia && BookingRequest::where('doctor_id', $datos['doctor_id'])->whereIn('status', ['held', 'processing'])
                ->whereRaw('(date + start_time) < ?::timestamp AND (date + end_time) > ?::timestamp', [$datos['ends_at'], $datos['starts_at']])->exists()) {
                throw ValidationException::withMessages(['schedule' => 'El período contiene un pago online pendiente de conciliación.']);
            }
            $afectadas = app(ScheduleImpact::class)->citasSuperpuestas($datos['doctor_id'], $datos['starts_at'], $datos['ends_at']);

            return ['unavailability' => $soloVistaPrevia ? null : Unavailability::create($datos), 'affected' => $afectadas];
        });
    }

    /** Retira el bloqueo, conservando fechas, motivo original, actor e historial. */
    public function retirarAusencia(int $id, string $motivo, User $responsable): array
    {
        return DB::transaction(function () use ($id, $motivo, $responsable) {
            $ausencia = Unavailability::findOrFail($id);
            Doctor::whereKey($ausencia->doctor_id)->lockForUpdate()->firstOrFail();
            $ausencia = Unavailability::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($ausencia->cancelled_at, 409, 'Esta ausencia ya fue retirada. Actualice la consulta.');
            $ausencia->fill(['cancelled_at' => now(), 'cancelled_by' => $responsable->id, 'cancellation_reason' => $motivo])->save();

            return ['unavailability' => $ausencia->fresh(), 'affected' => app(ScheduleImpact::class)->citasAfectadas($ausencia->doctor_id)];
        });
    }

    /** Bloquea la fila del médico activo para serializar cambios concurrentes de disponibilidad. */
    private function bloquearMedicoActivo(int $id): void
    {
        $medico = Doctor::whereKey($id)->lockForUpdate()->firstOrFail();
        if ($medico->status !== 'Activo') {
            throw ValidationException::withMessages(['doctor_id' => 'El médico debe estar activo.']);
        }
    }
}
