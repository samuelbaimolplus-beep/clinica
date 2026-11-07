<?php

namespace App\Services\Schedules;

use App\Models\Schedule;
use Carbon\CarbonImmutable;

class ScheduleBlocks
{
    public function forDate(int $doctorId, string $date): array
    {
        $day = CarbonImmutable::parse($date);
        $ranges = Schedule::where('doctor_id', $doctorId)->where('weekday', $day->dayOfWeek)->orderBy('start_time')->get();
        $blocks = [];
        foreach ($ranges as $range) {
            $end = CarbonImmutable::parse($date.' '.$range->end_time);
            for ($start = CarbonImmutable::parse($date.' '.$range->start_time); $start->addMinutes($range->duration) <= $end; $start = $start->addMinutes($range->duration)) {
                // Una consulta que cruza el límite de 120 minutos permanece protegida entera.
                $blocks[] = ['schedule_id' => $range->id, 'start' => $start, 'end' => $start->addMinutes($range->duration),
                    'protected' => $start < CarbonImmutable::parse($date.' '.$range->start_time)->addMinutes(120)];
            }
        }

        return $blocks;
    }
}
