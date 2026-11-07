<?php

namespace App\Services\Catalog;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\SpecialtyName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** CU03–CU06: operaciones atómicas; las entidades se conservan mediante su estado. */
class CatalogService
{
    public function save(Model $record, array $data, ?User $actor = null): Model
    {
        return DB::transaction(function () use ($record, $data, $actor) {
            if ($record instanceof User) {
                // Orden estable para impedir que cambios simultáneos eliminen todos los administradores.
                User::orderBy('id')->lockForUpdate()->get();
                $current = $actor?->fresh();
                abort_unless($current?->status === 'Activo' && $current?->role === 'Administrador', 403);
                abort_if($record->exists && $record->fresh()->role === 'Paciente', 403);
                if ($record->getKey() === $actor->id && ($data['status'] !== 'Activo' || $data['role'] !== $actor->role)) {
                    throw ValidationException::withMessages(['status' => 'No puede desactivar su propia cuenta ni cambiar su propio rol.']);
                }
                if ($record->exists && $record->fresh()->role === 'Administrador' && ($data['status'] !== 'Activo' || $data['role'] !== 'Administrador') && User::where('role', 'Administrador')->where('status', 'Activo')->count() <= 1) {
                    throw ValidationException::withMessages(['status' => 'Debe conservar al menos un administrador activo.']);
                }
                if (empty($data['password'])) {
                    unset($data['password']);
                }
                $data['doctor_id'] = $data['role'] === 'Médico' ? $data['doctor_id'] : null;
            } elseif ($record->exists) {
                $record = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            }
            if ($record instanceof Specialty && isset($data['name']) && Specialty::whereRaw("lower(trim(regexp_replace(name, '[[:space:]]+', ' ', 'g'))) = ?", [SpecialtyName::key($data['name'])])->when($record->exists, fn ($q) => $q->whereKeyNot($record->id))->exists()) {
                throw ValidationException::withMessages(['name' => 'Ya existe una especialidad con ese nombre.']);
            }
            if ($record instanceof Patient && array_key_exists('identity', $data) && empty($data['identity'])) {
                $data['identity'] = null;
                // Sin CI no se fusionan personas automáticamente; recepción revisa coincidencias exactas.
                $duplicate = Patient::whereRaw('lower(first_name) = ?', [mb_strtolower($data['first_name'])])
                    ->whereRaw('lower(last_name) = ?', [mb_strtolower($data['last_name'])])
                    ->where('birth_date', $data['birth_date'])->where('phone', $data['phone'])
                    ->when($record->exists, fn ($q) => $q->whereKeyNot($record->id))->exists();
                if ($duplicate) {
                    throw ValidationException::withMessages(['identity' => 'Existe un paciente con los mismos datos. Revise el registro antes de continuar.']);
                }
            }
            $specialties = $data['specialty_ids'] ?? null;
            if ($specialties !== null) {
                $selected = Specialty::whereIn('id', $specialties)->orderBy('id')->lockForUpdate()->get();
                if ($selected->count() !== count($specialties) || $selected->contains(fn ($specialty) => $specialty->status !== 'Activo')) {
                    throw ValidationException::withMessages(['specialty_ids' => 'Seleccione únicamente especialidades registradas y activas.']);
                }
            }
            unset($data['specialty_ids']);
            $record->fill($data);
            if ($record instanceof User && $record->isDirty('email')) {
                $record->email_verified_at = null;
            }
            $record->save();
            if ($record instanceof Doctor && $specialties !== null) {
                $changes = $record->specialties()->sync($specialties);
                if ($changes['attached'] || $changes['detached']) {
                    app(AuditLogger::class)->record('doctors', 'specialties_assigned', $record, details: ['specialty_ids' => array_map('intval', $specialties)]);
                }
            }

            $record = $record->fresh($record instanceof Doctor ? ['specialties'] : []);
            if ($record instanceof Doctor || $record instanceof Specialty) {
                $record->setAttribute('affected_appointments', app(CatalogImpact::class)->affected(
                    $record instanceof Doctor ? $record->id : null,
                    $record instanceof Specialty ? $record->id : null,
                ));
            }

            return $record;
        });
    }

    public function changeStatus(Model $record, string $status, User $actor): Model
    {
        return DB::transaction(function () use ($record, $status, $actor) {
            if ($record instanceof User) {
                User::orderBy('id')->lockForUpdate()->get();
            }
            $record = $record->newQuery()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $data = $record instanceof User ? $record->only(['name', 'email', 'role', 'doctor_id']) : [];

            return $this->save($record, $data + ['status' => $status], $actor);
        });
    }
}
