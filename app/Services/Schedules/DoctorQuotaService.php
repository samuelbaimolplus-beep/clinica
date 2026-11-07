<?php

namespace App\Services\Schedules;

use App\Models\AgendaAdjustment;
use App\Models\BookingRequest;
use App\Models\Doctor;
use App\Models\DoctorQuota;
use App\Models\Unavailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DoctorQuotaService
{
    public function save(array $data): DoctorQuota
    {
        if ($this->basisPoints($data['presential_percent']) + $this->basisPoints($data['online_percent']) !== 10000) {
            throw ValidationException::withMessages(['presential_percent' => 'Los porcentajes presenciales y online deben sumar exactamente 100%.']);
        }

        return DB::transaction(function () use ($data) {
            $doctor = Doctor::whereKey($data['doctor_id'])->lockForUpdate()->firstOrFail();
            if (BookingRequest::where('doctor_id', $doctor->id)->whereIn('status', ['held', 'processing'])->exists()) {
                throw ValidationException::withMessages(['quota' => 'Concilie los pagos online retenidos antes de cambiar la distribución.']);
            }
            if ($doctor->status !== 'Activo') {
                throw ValidationException::withMessages(['doctor_id' => 'El médico debe estar activo.']);
            }

            return DoctorQuota::updateOrCreate(['doctor_id' => $doctor->id], $data);
        });
    }

    public function capacity(int $doctorId, string $date): array
    {
        $ranges = app(ShiftModalities::class)->forDate($doctorId, $date);
        $day = CarbonImmutable::parse($date);
        $blocks = Unavailability::where('doctor_id', $doctorId)->where('starts_at', '<', $day->addDay())->where('ends_at', '>', $day)->get();
        $usable = array_filter($ranges, fn ($range) => ! $blocks->contains(fn ($block) => CarbonImmutable::parse($block->starts_at) < $range['end'] && CarbonImmutable::parse($block->ends_at) > $range['start']));
        $adjustments = AgendaAdjustment::where('doctor_id', $doctorId)->where('date', $date)->where('status', 'active')
            ->when($date !== today()->toDateString(), fn ($query) => $query->where('kind', 'online_opening'))->get();
        $usable = array_map(function ($block) use ($adjustments) {
            $adjustment = $adjustments->first(fn ($item) => $item->start_time === $block['start']->format('H:i:s') && $item->end_time === $block['end']->format('H:i:s'));
            $block['demand_blocked'] = $adjustment?->kind === 'presential_block';
            $block['early_online'] = $adjustment?->kind === 'online_opening';
            if ($adjustment) {
                $block['modality'] = $block['early_online'] ? 'online' : 'presential';
            }

            return $block;
        }, $usable);
        $total = count($usable);
        $quota = DoctorQuota::where('doctor_id', $doctorId)->first();
        $presential = count(array_filter($usable, fn ($block) => $block['modality'] === 'presential'));
        $online = $total - $presential;

        return ['date' => $date, 'configured_blocks' => count($ranges), 'blocked_blocks' => count($ranges) - $total,
            'total_capacity' => $total, 'quota' => $quota ?? ['presential_percent' => 50, 'online_percent' => 50],
            'uses_default_quota' => $quota === null, 'protection_minutes' => 120,
            'protected_capacity' => count(array_filter($usable, fn ($block) => $block['protected'])),
            'presential_capacity' => $presential, 'online_capacity' => $online,
            'rounding_policy' => 'largest_remainder_presential_tie',
            'blocks' => array_map(fn ($block) => ['schedule_id' => $block['schedule_id'],
                'start_time' => $block['start']->format('H:i'), 'end_time' => $block['end']->format('H:i'),
                'protected' => $block['protected'], 'modality' => $block['modality'],
                'demand_blocked' => $block['demand_blocked'], 'early_online' => $block['early_online']], array_values($usable))];
    }

    private function basisPoints(string|int|float $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
