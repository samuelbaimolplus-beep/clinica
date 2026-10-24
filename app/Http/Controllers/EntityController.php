<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EntityController extends Controller
{
    private const MODELS = ['users' => User::class, 'doctors' => Doctor::class, 'specialties' => Specialty::class, 'patients' => Patient::class];

    private function authorizeEntity(Request $r, string $entity): string
    {
        abort_unless(isset(self::MODELS[$entity]), 404);
        abort_unless($r->user()->role === ($entity === 'patients' ? 'Recepcionista' : 'Administrador'), 403, 'Su rol no tiene permiso para esta operación.');

        return self::MODELS[$entity];
    }

    public function references(Request $r)
    {
        return response()->json([
            'doctors' => Doctor::with('specialties')->orderBy('last_name')->get(),
            'specialties' => Specialty::orderBy('name')->get(),
            'patients' => $r->user()->role !== 'Médico' ? Patient::orderBy('last_name')->get() : [],
        ]);
    }

    public function index(Request $r, string $entity)
    {
        $model = $this->authorizeEntity($r, $entity);
        $query = $model::query();
        if ($entity === 'doctors') {
            $query->with('specialties');
        }

        return $query->orderBy('id')->get();
    }

    public function save(Request $r, string $entity, ?int $id = null)
    {
        $model = $this->authorizeEntity($r, $entity);
        $record = $id ? $model::findOrFail($id) : new $model;
        $status = ['required', Rule::in(['Activo', 'Inactivo'])];
        $person = ['first_name' => 'required|string|max:100', 'last_name' => 'required|string|max:150', 'phone' => 'required|string|max:30', 'status' => $status];
        $rules = match ($entity) {
            'users' => [
                'name' => 'required|string|max:150', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($id)],
                'password' => [$id ? 'nullable' : 'required', 'string', 'min:10', 'max:128'],
                'role' => ['required', Rule::in(['Administrador', 'Recepcionista', 'Médico'])], 'status' => $status,
                'doctor_id' => ['nullable', 'required_if:role,Médico', 'prohibited_unless:role,Médico', Rule::exists('doctors', 'id')->where(fn ($q) => $q->where('status', 'Activo')->orWhere('id', $record->doctor_id)), Rule::unique('users')->ignore($id)],
            ],
            'doctors' => $person + ['registration' => ['required', 'string', 'max:80', Rule::unique('doctors')->ignore($id)], 'email' => ['required', 'email', 'max:255', Rule::unique('doctors')->ignore($id)], 'specialty_ids' => 'required|array|min:1', 'specialty_ids.*' => ['integer', 'distinct', Rule::exists('specialties', 'id')->where(fn ($q) => $q->where('status', 'Activo')->orWhereIn('id', $record->exists ? $record->specialties()->pluck('specialties.id')->all() : []))]],
            'specialties' => ['name' => ['required', 'string', 'max:120', Rule::unique('specialties')->ignore($id)], 'description' => 'nullable|string|max:3000', 'status' => $status],
            'patients' => $person + ['identity' => ['required', 'string', 'max:40', Rule::unique('patients')->ignore($id)], 'birth_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'sex' => ['required', Rule::in(['Femenino', 'Masculino'])], 'email' => 'nullable|email|max:255', 'address' => 'nullable|string|max:1000'],
        };
        $r->merge(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $r->only(['name', 'first_name', 'last_name', 'email', 'identity', 'registration'])));
        if ($r->filled('email')) {
            $r->merge(['email' => mb_strtolower($r->string('email')->trim()->toString())]);
        }
        if ($entity === 'patients') {
            $r->merge(['identity' => mb_strtoupper(trim((string) $r->input('identity')))]);
        }
        if ($entity === 'doctors') {
            $r->merge(['registration' => mb_strtoupper(trim((string) $r->input('registration')))]);
        }
        $data = $r->validate($rules);
        if ($entity === 'specialties' && Specialty::whereRaw('lower(name) = ?', [mb_strtolower($data['name'])])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
            throw ValidationException::withMessages(['name' => 'Ya existe una especialidad con ese nombre.']);
        }

        return DB::transaction(function () use ($entity, $id, $record, $data, $r) {
            if ($entity === 'users') {
                // Serialize account administration so concurrent changes cannot remove every administrator.
                User::orderBy('id')->lockForUpdate()->get();
                $actor = $r->user()->fresh();
                abort_unless($actor->status === 'Activo' && $actor->role === 'Administrador', 403);
                if ($id === $r->user()->id && ($data['status'] !== 'Activo' || $data['role'] !== $r->user()->role)) {
                    throw ValidationException::withMessages(['status' => 'No puede desactivar su propia cuenta ni cambiar su propio rol.']);
                }
                if ($record->exists && $record->fresh()->role === 'Administrador' && ($data['status'] !== 'Activo' || $data['role'] !== 'Administrador') && User::where('role', 'Administrador')->where('status', 'Activo')->count() <= 1) {
                    throw ValidationException::withMessages(['status' => 'Debe conservar al menos un administrador activo.']);
                }
                if (empty($data['password'])) {
                    unset($data['password']);
                }
                $data['doctor_id'] = $data['role'] === 'Médico' ? $data['doctor_id'] : null;
            }
            $specialties = $data['specialty_ids'] ?? null;
            unset($data['specialty_ids']);
            $record->fill($data)->save();
            if ($specialties !== null) {
                $record->specialties()->sync($specialties);
            }

            return response()->json($record->fresh($entity === 'doctors' ? ['specialties'] : []), $id ? 200 : 201);
        });
    }
}
