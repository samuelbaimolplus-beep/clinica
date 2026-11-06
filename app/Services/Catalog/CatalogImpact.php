<?php

namespace App\Services\Catalog;

use App\Models\Appointment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** CU04–CU05: identificar conflictos de configuración; nunca cambiar el estado de la cita. */
class CatalogImpact
{
    public function affected(?int $doctorId = null, ?int $specialtyId = null): Collection
    {
        return Appointment::query()->with(['doctor:id,status', 'doctor.specialties:id', 'specialty:id,status'])
            ->whereIn('status', Appointment::ACTIVE)
            ->whereRaw('(date + start_time) >= ?::timestamp', [now()->format('Y-m-d H:i:s')])
            ->when($doctorId, fn ($query) => $query->where('doctor_id', $doctorId))
            ->when($specialtyId, fn ($query) => $query->where('specialty_id', $specialtyId))
            ->where(function (Builder $query) {
                $query->whereHas('doctor', fn ($doctor) => $doctor->where('status', 'Inactivo'))
                    ->orWhereHas('specialty', fn ($specialty) => $specialty->where('status', 'Inactivo'))
                    ->orWhereNotExists(fn ($link) => $link->selectRaw('1')->from('doctor_specialty')
                        ->whereColumn('doctor_specialty.doctor_id', 'appointments.doctor_id')
                        ->whereColumn('doctor_specialty.specialty_id', 'appointments.specialty_id'));
            })->orderBy('date')->orderBy('start_time')->get()
            ->map(function (Appointment $appointment) {
                $reasons = [];
                if ($appointment->doctor->status !== 'Activo') {
                    $reasons[] = 'Médico inactivo';
                }
                if ($appointment->specialty->status !== 'Activo') {
                    $reasons[] = 'Especialidad inactiva';
                }
                if (! $appointment->doctor->specialties->contains('id', $appointment->specialty_id)) {
                    $reasons[] = 'Especialidad retirada del médico';
                }

                return $appointment->only(['id', 'doctor_id', 'specialty_id', 'date', 'start_time', 'end_time', 'status'])
                    + ['reasons' => $reasons];
            });
    }
}
