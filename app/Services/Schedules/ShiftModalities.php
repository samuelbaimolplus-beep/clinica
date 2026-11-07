<?php

namespace App\Services\Schedules;

use App\Models\DoctorQuota;

/** RN35–RN36: proteger cada turno y alternar el resto hasta agotar sus cuotas. */
class ShiftModalities
{
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
