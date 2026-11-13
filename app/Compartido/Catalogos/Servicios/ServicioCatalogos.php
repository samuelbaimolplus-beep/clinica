<?php

/** Altas y modificaciones atómicas de catálogos, con roles, duplicados y conservación del historial. */

namespace App\Compartido\Catalogos\Servicios;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\RegistroAuditoria;
use App\Support\SpecialtyName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** CU03–CU06: operaciones atómicas; las entidades se conservan mediante su estado. */
class ServicioCatalogos
{
    /** Coordina la validación y persistencia de esta operación; delega las reglas del dominio al servicio correspondiente. */
    public function guardar(Model $registro, array $datos, ?User $responsable = null): Model
    {
        return DB::transaction(function () use ($registro, $datos, $responsable) {
            if ($registro instanceof User) {
                // Orden estable para impedir que cambios simultáneos eliminen todos los administradores.
                User::orderBy('id')->lockForUpdate()->get();
                $actual = $responsable?->fresh();
                abort_unless($actual?->status === 'Activo' && $actual?->hasStaffRole('Administrador'), 403);
                abort_if($registro->exists && $registro->fresh()->role === 'Paciente', 403);
                abort_if($actual->role !== 'Superadmin' && ($datos['role'] === 'Superadmin' || ($registro->exists && $registro->fresh()->role === 'Superadmin')), 403);
                if ($registro->getKey() === $responsable->id && ($datos['status'] !== 'Activo' || $datos['role'] !== $responsable->role)) {
                    throw ValidationException::withMessages(['status' => 'No puede desactivar su propia cuenta ni cambiar su propio rol.']);
                }
                if ($registro->exists && $registro->fresh()->role === 'Administrador' && ($datos['status'] !== 'Activo' || $datos['role'] !== 'Administrador') && User::where('role', 'Administrador')->where('status', 'Activo')->count() <= 1) {
                    throw ValidationException::withMessages(['status' => 'Debe conservar al menos un administrador activo.']);
                }
                if ($registro->exists && $registro->fresh()->role === 'Superadmin' && ($datos['status'] !== 'Activo' || $datos['role'] !== 'Superadmin') && User::where('role', 'Superadmin')->where('status', 'Activo')->count() <= 1) {
                    throw ValidationException::withMessages(['status' => 'Debe conservar al menos un Superadmin activo.']);
                }
                if (empty($datos['password'])) {
                    unset($datos['password']);
                }
                $datos['doctor_id'] = $datos['role'] === 'Médico' ? $datos['doctor_id'] : null;
            } elseif ($registro->exists) {
                $registro = $registro->newQuery()->whereKey($registro->getKey())->lockForUpdate()->firstOrFail();
            }
            if ($registro instanceof Specialty && isset($datos['name']) && Specialty::whereRaw("lower(trim(regexp_replace(name, '[[:space:]]+', ' ', 'g'))) = ?", [SpecialtyName::key($datos['name'])])->when($registro->exists, fn ($consulta) => $consulta->whereKeyNot($registro->id))->exists()) {
                throw ValidationException::withMessages(['name' => 'Ya existe una especialidad con ese nombre.']);
            }
            if ($registro instanceof Patient && array_key_exists('identity', $datos) && empty($datos['identity'])) {
                $datos['identity'] = null;
                // Sin CI no se fusionan personas automáticamente; recepción revisa coincidencias exactas.
                $duplicado = Patient::whereRaw('lower(first_name) = ?', [mb_strtolower($datos['first_name'])])
                    ->whereRaw('lower(last_name) = ?', [mb_strtolower($datos['last_name'])])
                    ->where('birth_date', $datos['birth_date'])->where('phone', $datos['phone'])
                    ->when($registro->exists, fn ($consulta) => $consulta->whereKeyNot($registro->id))->exists();
                if ($duplicado) {
                    throw ValidationException::withMessages(['identity' => 'Existe un paciente con los mismos datos. Revise el registro antes de continuar.']);
                }
            }
            $especialidades = $datos['specialty_ids'] ?? null;
            if ($especialidades !== null) {
                $seleccionados = Specialty::whereIn('id', $especialidades)->orderBy('id')->lockForUpdate()->get();
                if ($seleccionados->count() !== count($especialidades) || $seleccionados->contains(fn ($especialidad) => $especialidad->status !== 'Activo')) {
                    throw ValidationException::withMessages(['specialty_ids' => 'Seleccione únicamente especialidades registradas y activas.']);
                }
            }
            unset($datos['specialty_ids']);
            $registro->fill($datos);
            if ($registro instanceof User && $registro->isDirty('email')) {
                $registro->email_verified_at = null;
            }
            $registro->save();
            if ($registro instanceof Doctor && $especialidades !== null) {
                $antes = $registro->specialties()->orderBy('specialties.id')->get(['specialties.id', 'specialties.name'])
                    ->map(fn ($especialidad) => ['id' => $especialidad->id, 'name' => $especialidad->name])->all();
                $cambios = $registro->specialties()->sync($especialidades);
                if ($cambios['attached'] || $cambios['detached']) {
                    $despues = $registro->specialties()->orderBy('specialties.id')->get(['specialties.id', 'specialties.name'])
                        ->map(fn ($especialidad) => ['id' => $especialidad->id, 'name' => $especialidad->name])->all();
                    app(RegistroAuditoria::class)->registrar('doctors', 'specialties_assigned', $registro, detalles: [
                        'specialty_ids' => array_map('intval', $especialidades), 'fields' => ['specialties'],
                        'changes' => ['specialties' => ['before' => $antes, 'after' => $despues]],
                    ]);
                }
            }

            $registro = $registro->fresh($registro instanceof Doctor ? ['specialties'] : []);
            if ($registro instanceof Doctor || $registro instanceof Specialty) {
                $registro->setAttribute('affected_appointments', app(ImpactoCatalogos::class)->citasAfectadas(
                    $registro instanceof Doctor ? $registro->id : null,
                    $registro instanceof Specialty ? $registro->id : null,
                ));
            }

            return $registro;
        });
    }

    /** Cambia el estado conservando el registro y sus relaciones históricas. */
    public function cambiarEstado(Model $registro, string $estado, User $responsable): Model
    {
        return DB::transaction(function () use ($registro, $estado, $responsable) {
            if ($registro instanceof User) {
                User::orderBy('id')->lockForUpdate()->get();
            }
            $registro = $registro->newQuery()->whereKey($registro->id)->lockForUpdate()->firstOrFail();
            $datos = $registro instanceof User ? $registro->only(['name', 'email', 'role', 'doctor_id', 'birth_date']) : [];

            return $this->guardar($registro, $datos + ['status' => $estado], $responsable);
        });
    }
}
