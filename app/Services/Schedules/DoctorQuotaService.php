<?php

namespace App\Services\Schedules;

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
            if ($doctor->status !== 'Activo') {
                throw ValidationException::withMessages(['doctor_id' => 'El médico debe estar activo.']);
            }

            return DoctorQuota::updateOrCreate(['doctor_id' => $doctor->id], $data);
        });
    }

    public function capacity(int $doctorId, string $date): array
    {
        $ranges = app(ScheduleBlocks::class)->forDate($doctorId, $date);
        $day = CarbonImmutable::parse($date);
        $blocks = Unavailability::where('doctor_id', $doctorId)->where('starts_at', '<', $day->addDay())->where('ends_at', '>', $day)->get();
        $usable = array_filter($ranges, fn ($range) => ! $blocks->contains(fn ($block) => CarbonImmutable::parse($block->starts_at) < $range['end'] && CarbonImmutable::parse($block->ends_at) > $range['start']));
        $total = count($usable);
        $quota = DoctorQuota::where('doctor_id', $doctorId)->first();
        $presential = null;
        $online = null;
        if ($quota) {
            $allocation = app(QuotaAllocator::class)->allocate($total,
                $this->basisPoints($quota->presential_percent), $this->basisPoints($quota->online_percent));
            $presential = $allocation['presential'];
            $online = $allocation['online'];
        }

        return ['date' => $date, 'configured_blocks' => count($ranges), 'blocked_blocks' => count($ranges) - $total,
            'total_capacity' => $total, 'quota' => $quota, 'presential_capacity' => $presential,
            'online_capacity' => $online, 'rounding_policy' => 'largest_remainder_presential_tie'];
    }

    private function basisPoints(string|int|float $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
