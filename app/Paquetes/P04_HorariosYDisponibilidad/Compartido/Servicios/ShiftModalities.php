<?php

/** Distribuye franjas semanales entre modalidades, conservando la protección presencial inicial. */

namespace App\Paquetes\P04_HorariosYDisponibilidad\Compartido\Servicios;

use App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Servicios\QuotaAllocator;
use App\Models\DoctorQuota;

/** RN35–RN36: proteger cada turno y alternar el resto hasta agotar sus cuotas. */
class ShiftModalities
{
    /** Construye las franjas aplicables al día solicitado a partir del horario semanal. */
    public function forDate(int $doctorId, string $date): array
    {
        $quota = DoctorQuota::where('doctor_id', $doctorId)->first();
        $presential = (int) round((float) ($quota?->presential_percent ?? 50) * 100);
        $result = [];
        foreach (collect(app(ScheduleBlocks::class)->forDate($doctorId, $date))->groupBy('schedule_id') as $shift) {
            $remaining = app(QuotaAllocator::class)->allocate($shift->where('protected', false)->count(), $presential, 10000 - $presential);
            $next = 'presential';
            foreach ($shift as $block) {
                $modality = 'presential';
                if (! $block['protected']) {
                    $modality = $remaining[$next] > 0 ? $next : ($next === 'presential' ? 'online' : 'presential');
                    $remaining[$modality]--;
                    $next = $modality === 'presential' ? 'online' : 'presential';
                }
                $result[] = $block + ['modality' => $modality];
            }
        }

        return $result;
    }
}
