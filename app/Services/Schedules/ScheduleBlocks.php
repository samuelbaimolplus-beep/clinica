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
                $blocks[] = ['start' => $start, 'end' => $start->addMinutes($range->duration)];
            }
        }

        return $blocks;
    }
}
