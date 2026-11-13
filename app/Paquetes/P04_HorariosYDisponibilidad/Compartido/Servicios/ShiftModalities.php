<?php

/** Distribuye franjas semanales entre modalidades, conservando la protección presencial inicial. */

namespace App\Paquetes\P04_HorariosYDisponibilidad\Compartido\Servicios;

use App\Models\DoctorQuota;
use App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Servicios\QuotaAllocator;

/** RN35–RN36: proteger cada turno y alternar el resto hasta agotar sus cuotas. */
class ShiftModalities
{
    /** Construye las franjas aplicables al día solicitado a partir del horario semanal. */
    public function franjasParaFecha(int $medicoId, string $fecha): array
    {
        $distribucion = DoctorQuota::where('doctor_id', $medicoId)->first();
        $presencial = (int) round((float) ($distribucion?->presential_percent ?? 50) * 100);
        $resultado = [];
        foreach (collect(app(ScheduleBlocks::class)->franjasParaFecha($medicoId, $fecha))->groupBy('schedule_id') as $turno) {
            $restantes = app(QuotaAllocator::class)->distribuir($turno->where('protected', false)->count(), $presencial, 10000 - $presencial);
            $siguiente = 'presential';
            foreach ($turno as $bloque) {
                $modalidad = 'presential';
                if (! $bloque['protected']) {
                    $modalidad = $restantes[$siguiente] > 0 ? $siguiente : ($siguiente === 'presential' ? 'online' : 'presential');
                    $restantes[$modalidad]--;
                    $siguiente = $modalidad === 'presential' ? 'online' : 'presential';
                }
                $resultado[] = $bloque + ['modality' => $modalidad];
            }
        }

        return $resultado;
    }
}
