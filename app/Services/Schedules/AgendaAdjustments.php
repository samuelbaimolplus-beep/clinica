<?php

namespace App\Services\Schedules;

use App\Models\AgendaAdjustment;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Specialty;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** RN35 y RN37: movimientos operativos de Recepción, sin pacientes ni cobros. */
class AgendaAdjustments
{
    public function freeBlocks(int $doctorId, string $date): array
    {
        $capacity = app(DoctorQuotaService::class)->capacity($doctorId, $date);
        $busy = Appointment::where('doctor_id', $doctorId)->where('date', $date)->whereIn('status', Appointment::ACTIVE)->get();

        return array_values(array_filter($capacity['blocks'], fn ($block) => ! $block['demand_blocked']
            && CarbonImmutable::parse($date.' '.$block['start_time']) >= now()
            && ! $busy->contains(fn ($appointment) => $appointment->start_time < $block['end_time'].':00' && $appointment->end_time > $block['start_time'].':00')));
    }

    public function propose(int $specialtyId, string $date, int $quantity): array
    {
        $candidates = [];
        if (Specialty::whereKey($specialtyId)->where('status', 'Activo')->exists()) {
            $doctors = Doctor::where('status', 'Activo')->whereHas('specialties', fn ($q) => $q->whereKey($specialtyId))->orderBy('id')->get();
            foreach ($doctors as $doctor) {
                foreach ($this->freeBlocks($doctor->id, $date) as $block) {
                    if ($block['modality'] === 'presential') {
                        $candidates[] = ['doctor_id' => $doctor->id, 'date' => $date,
                            'start_time' => $block['start_time'], 'end_time' => $block['end_time']];
                    }
                }
            }
        }
        usort($candidates, fn ($a, $b) => [$a['start_time'], $a['doctor_id']] <=> [$b['start_time'], $b['doctor_id']]);
        $selected = array_slice($candidates, 0, $quantity);

        return ['blocks' => $selected, 'requested' => $quantity, 'unfulfilled' => $quantity - count($selected)];
    }

    public function create(string $kind, ?int $specialtyId, array $blocks, User $actor): array
    {
        return DB::transaction(function () use ($kind, $specialtyId, $blocks, $actor) {
            $doctors = Doctor::whereIn('id', array_column($blocks, 'doctor_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($kind === 'presential_block' && ! Specialty::whereKey($specialtyId)->where('status', 'Activo')->exists()) {
                $this->fail('Seleccione una especialidad activa.');
            }
            $created = [];
            foreach ($blocks as $data) {
                $doctor = $doctors->get($data['doctor_id']);
                if (! $doctor || $doctor->status !== 'Activo' || ($specialtyId && ! $doctor->specialties()->whereKey($specialtyId)->exists())) {
                    $this->fail('El médico ya no está habilitado para esta operación.');
                }
                $block = collect($this->freeBlocks($doctor->id, $data['date']))->first(fn ($item) => $item['start_time'] === $data['start_time'] && $item['end_time'] === $data['end_time']);
                if (! $block || $block['modality'] !== 'presential'
                    || ($kind === 'online_opening' && ! $block['protected'])) {
                    $this->fail('Una franja ya no está libre o no admite la operación. Consulte nuevamente.');
                }
                $created[] = AgendaAdjustment::create($data + ['specialty_id' => $specialtyId, 'kind' => $kind, 'created_by' => $actor->id]);
            }

            return $created;
        });
    }

    public function release(int $id, User $actor): AgendaAdjustment
    {
        return DB::transaction(function () use ($id, $actor) {
            $record = AgendaAdjustment::findOrFail($id);
            Doctor::whereKey($record->doctor_id)->lockForUpdate()->firstOrFail();
            $record = AgendaAdjustment::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($record->status !== 'active') {
                $this->fail('El movimiento ya no está activo. Actualice la consulta.');
            }
            $record->update(['status' => 'released', 'released_by' => $actor->id, 'released_at' => now()]);

            return $record;
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['blocks' => $message]);
    }
}
