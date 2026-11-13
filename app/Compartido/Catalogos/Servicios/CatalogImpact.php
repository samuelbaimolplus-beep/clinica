<?php

/** Calcula citas afectadas por cambios administrativos sin cancelarlas automáticamente. */

namespace App\Compartido\Catalogos\Servicios;

use App\Models\Appointment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** CU04–CU05: identificar conflictos de configuración; nunca cambiar el estado de la cita. */
class CatalogImpact
{
    /** Calcula las citas afectadas por el cambio conservando su programación y estado. */
    public function citasAfectadas(?int $medicoId = null, ?int $especialidadId = null): Collection
    {
        return Appointment::query()->with(['doctor:id,status', 'doctor.specialties:id', 'specialty:id,status'])
            ->whereIn('status', Appointment::ACTIVE)
            ->whereRaw('(date + start_time) >= ?::timestamp', [now()->format('Y-m-d H:i:s')])
            ->when($medicoId, fn ($consultaDatos) => $consultaDatos->where('doctor_id', $medicoId))
            ->when($especialidadId, fn ($consultaDatos) => $consultaDatos->where('specialty_id', $especialidadId))
            ->where(function (Builder $consultaDatos) {
                $consultaDatos->whereHas('doctor', fn ($medico) => $medico->where('status', 'Inactivo'))
                    ->orWhereHas('specialty', fn ($especialidad) => $especialidad->where('status', 'Inactivo'))
                    ->orWhereNotExists(fn ($vinculo) => $vinculo->selectRaw('1')->from('doctor_specialty')
                        ->whereColumn('doctor_specialty.doctor_id', 'appointments.doctor_id')
                        ->whereColumn('doctor_specialty.specialty_id', 'appointments.specialty_id'));
            })->orderBy('date')->orderBy('start_time')->get()
            ->map(function (Appointment $cita) {
                $motivos = [];
                $origenes = [];
                if ($cita->doctor->status !== 'Activo') {
                    $motivos[] = 'Médico inactivo';
                    $origenes[] = ['entity' => 'Doctor', 'id' => $cita->doctor_id];
                }
                if ($cita->specialty->status !== 'Activo') {
                    $motivos[] = 'Especialidad inactiva';
                    $origenes[] = ['entity' => 'Specialty', 'id' => $cita->specialty_id];
                }
                if (! $cita->doctor->specialties->contains('id', $cita->specialty_id)) {
                    $motivos[] = 'Especialidad retirada del médico';
                    $origenes[] = ['entity' => 'DoctorSpecialty', 'doctor_id' => $cita->doctor_id, 'specialty_id' => $cita->specialty_id];
                }

                return $cita->only(['id', 'doctor_id', 'specialty_id', 'date', 'start_time', 'end_time', 'status'])
                    + ['reasons' => $motivos, 'origins' => $origenes];
            });
    }
}
