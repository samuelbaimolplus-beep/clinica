<?php

/** Propone, confirma y libera protección de demanda; distingue reservas online y bloqueos presenciales. */

namespace App\Paquetes\P04_HorariosYDisponibilidad\Compartido\Servicios;

use App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Servicios\DoctorQuotaService;
use App\Models\AgendaAdjustment;
use App\Models\Appointment;
use App\Models\BookingRequest;
use App\Models\Doctor;
use App\Models\Specialty;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** RN35 y RN37: movimientos operativos de Recepción, sin pacientes ni cobros. */
class AgendaAdjustments
{
    /** Selecciona únicamente franjas que pueden utilizarse sin conflictos. */
    public function freeBlocks(int $doctorId, string $date): array
    {
        $capacity = app(DoctorQuotaService::class)->capacity($doctorId, $date);
        $busy = Appointment::where('doctor_id', $doctorId)->where('date', $date)->whereIn('status', Appointment::ACTIVE)->get();
        $holds = BookingRequest::where('doctor_id', $doctorId)->where('date', $date)->whereIn('status', ['held', 'processing'])->get();

        return array_values(array_filter($capacity['blocks'], fn ($block) => ! $block['demand_blocked']
            && ! $holds->contains(fn ($hold) => $hold->start_time < $block['end_time'].':00' && $hold->end_time > $block['start_time'].':00')
            && CarbonImmutable::parse($date.' '.$block['start_time']) >= now()
            && ! $busy->contains(fn ($appointment) => $appointment->start_time < $block['end_time'].':00' && $appointment->end_time > $block['start_time'].':00')));
    }

    /** Calcula una propuesta de franjas; todavía no confirma bloqueos ni crea citas. */
    public function propose(int $specialtyId, string $date, int $quantity): array
    {
        $this->requireToday($date);
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

    /** Confirma las franjas propuestas como bloqueos presenciales o aperturas online, revalidando su disponibilidad. */
    public function create(string $kind, ?int $specialtyId, array $blocks, User $actor, ?int $quantity = null): array
    {
        return DB::transaction(function () use ($kind, $specialtyId, $blocks, $actor, $quantity) {
            $batch = $kind === 'presential_block' ? (string) Str::uuid() : null;
            $doctors = Doctor::whereIn('id', array_column($blocks, 'doctor_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($kind === 'presential_block' && ! Specialty::whereKey($specialtyId)->where('status', 'Activo')->exists()) {
                $this->fail('Seleccione una especialidad activa.');
            }
            $created = [];
            foreach ($blocks as $data) {
                if ($kind === 'presential_block') {
                    $this->requireToday($data['date']);
                }
                $doctor = $doctors->get($data['doctor_id']);
                if (! $doctor || $doctor->status !== 'Activo' || ($specialtyId && ! $doctor->specialties()->whereKey($specialtyId)->exists())) {
                    $this->fail('El médico ya no está habilitado para esta operación.');
                }
                $block = collect($this->freeBlocks($doctor->id, $data['date']))->first(fn ($item) => $item['start_time'] === $data['start_time'] && $item['end_time'] === $data['end_time']);
                if (! $block || $block['modality'] !== 'presential'
                    || ($kind === 'online_opening' && ! $block['protected'])) {
                    $this->fail('Una franja ya no está libre o no admite la operación. Consulte nuevamente.');
                }
                $created[] = AgendaAdjustment::create($data + ['specialty_id' => $specialtyId, 'kind' => $kind, 'created_by' => $actor->id,
                    'demand_batch' => $batch, 'demand_quantity' => $batch ? ($quantity ?? count($blocks)) : null]);
            }

            return $created;
        });
    }

    /** Libera un bloqueo autorizado conservando el registro de la operación. */
    public function release(int $id, User $actor): AgendaAdjustment
    {
        return DB::transaction(function () use ($id, $actor) {
            $record = AgendaAdjustment::findOrFail($id);
            Doctor::whereKey($record->doctor_id)->lockForUpdate()->firstOrFail();
            $record = AgendaAdjustment::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($record->kind !== 'presential_block') {
                $this->fail('CU25 no permite retirar una habilitación online.');
            }
            $this->requireToday($record->date);
            if ($record->status !== 'active') {
                $this->fail('El movimiento ya no está activo. Actualice la consulta.');
            }
            if (Appointment::where('doctor_id', $record->doctor_id)->where('date', $record->date)
                ->whereIn('status', Appointment::ACTIVE)->where('start_time', '<', $record->end_time)->where('end_time', '>', $record->start_time)->exists()) {
                $this->fail('El bloqueo está asociado a una cita vigente y no puede liberarse.');
            }
            $record->update(['status' => 'released', 'released_by' => $actor->id, 'released_at' => now()]);

            return $record;
        });
    }

    /** Libera los bloqueos seleccionados después de validar sus estados y permisos. */
    public function releaseSelected(array $ids, User $actor): array
    {
        return DB::transaction(function () use ($ids, $actor) {
            $records = AgendaAdjustment::whereIn('id', $ids)->get();
            if ($records->count() !== count($ids)) {
                $this->fail('Uno de los bloqueos ya no existe. Actualice la consulta.');
            }
            Doctor::whereIn('id', $records->pluck('doctor_id'))->orderBy('id')->lockForUpdate()->get();

            return array_map(fn ($id) => $this->release($id, $actor), $ids);
        });
    }

    /** Interrumpe la operación con un error de validación comprensible. */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['blocks' => $message]);
    }

    /** Rechaza los ajustes de demanda que no corresponden a la jornada actual. */
    private function requireToday(string $date): void
    {
        if ($date !== today()->toDateString()) {
            throw ValidationException::withMessages(['date' => 'CU24 permite gestionar bloqueos únicamente durante la jornada actual.']);
        }
    }
}
