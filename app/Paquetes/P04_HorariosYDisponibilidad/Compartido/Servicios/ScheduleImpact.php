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
    public function citasAfectadas(?int $medicoId = null): Collection
    {
        $citas = Appointment::whereIn('status', Appointment::ACTIVE)
            ->when($medicoId, fn ($consulta) => $consulta->where('doctor_id', $medicoId))->orderBy('date')->orderBy('start_time')->get();
        $ausencias = Unavailability::active()->whereIn('doctor_id', $citas->pluck('doctor_id')->unique())->get()->groupBy('doctor_id');
        $intervalos = [];

        return $citas->map(function ($cita) use (&$intervalos, $ausencias) {
            $clave = $cita->doctor_id.':'.$cita->date;
            $intervalos[$clave] ??= app(ScheduleBlocks::class)->franjasParaFecha($cita->doctor_id, $cita->date);
            $inicio = CarbonImmutable::parse($cita->date.' '.$cita->start_time);
            $fin = CarbonImmutable::parse($cita->date.' '.$cita->end_time);
            $motivos = [];
            $origenes = [];
            if ($cita->date >= today()->toDateString() && ! collect($intervalos[$clave])->contains(fn ($intervalo) => $intervalo['start']->equalTo($inicio) && $intervalo['end']->equalTo($fin))) {
                $motivos[] = 'Horario o duración fuera de la configuración vigente';
                $origenes[] = ['entity' => 'DoctorSchedule', 'doctor_id' => $cita->doctor_id,
                    'schedule_ids' => collect($intervalos[$clave])->pluck('schedule_id')->unique()->values()->all()];
            }
            if (($ausencias[$cita->doctor_id] ?? collect())->contains(fn ($ausencia) => CarbonImmutable::parse($ausencia->starts_at) < $fin && CarbonImmutable::parse($ausencia->ends_at) > $inicio)) {
                $motivos[] = 'Indisponibilidad médica';
                foreach ($ausencias[$cita->doctor_id] as $ausencia) {
                    if (CarbonImmutable::parse($ausencia->starts_at) < $fin && CarbonImmutable::parse($ausencia->ends_at) > $inicio) {
                        $origenes[] = ['entity' => 'Unavailability', 'id' => $ausencia->id];
                    }
                }
            }

            return $cita->only(['id', 'doctor_id', 'specialty_id', 'date', 'start_time', 'end_time', 'status']) + ['reasons' => $motivos, 'origins' => $origenes];
        })->filter(fn ($elementoLista) => count($elementoLista['reasons']) > 0)->values();
    }

    /** Una cita pendiente no deja de estar afectada porque ya pasó su hora de inicio. */
    public function citasSuperpuestas(int $medicoId, string $iniciaEl, string $terminaEl): Collection
    {
        return Appointment::with(['patient:id,first_name,last_name,phone', 'doctor:id,first_name,last_name', 'specialty:id,name'])
            ->where('doctor_id', $medicoId)->whereIn('status', Appointment::ACTIVE)
            ->whereRaw('(date + start_time) < ?::timestamp AND (date + end_time) > ?::timestamp', [$terminaEl, $iniciaEl])
            ->orderBy('date')->orderBy('start_time')->orderBy('id')->get();
    }
}
