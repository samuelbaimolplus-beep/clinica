<?php

/** Calcula las franjas que forman un turno y aplica las indisponibilidades correspondientes. */

namespace App\Paquetes\P04_HorariosYDisponibilidad\Compartido\Servicios;

use App\Models\Schedule;
use Carbon\CarbonImmutable;

class ScheduleBlocks
{
    /** Construye las franjas aplicables al día solicitado a partir del horario semanal. */
    public function franjasParaFecha(int $medicoId, string $fecha): array
    {
        $dia = CarbonImmutable::parse($fecha);
        $intervalos = Schedule::where('doctor_id', $medicoId)->where('weekday', $dia->dayOfWeek)->orderBy('start_time')->get();
        $bloques = [];
        foreach ($intervalos as $intervalo) {
            $fin = CarbonImmutable::parse($fecha.' '.$intervalo->end_time);
            for ($inicio = CarbonImmutable::parse($fecha.' '.$intervalo->start_time); $inicio->addMinutes($intervalo->duration) <= $fin; $inicio = $inicio->addMinutes($intervalo->duration)) {
                // Una consulta que cruza el límite de 120 minutos permanece protegida entera.
                $bloques[] = ['schedule_id' => $intervalo->id, 'start' => $inicio, 'end' => $inicio->addMinutes($intervalo->duration),
                    'protected' => $inicio < CarbonImmutable::parse($fecha.' '.$intervalo->start_time)->addMinutes(120)];
            }
        }

        return $bloques;
    }
}
