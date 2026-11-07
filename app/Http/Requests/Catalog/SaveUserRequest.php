<?php

namespace App\Http\Requests\Catalog;

use App\Models\User;
use App\Support\Username;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveUserRequest extends FormRequest
{
    use PersonRules;

    public function authorize(): bool
    {
        return $this->user()->can('manage-users');
    }

    public function rules(): array
    {
        $id = $this->route('id');
        $record = $id ? User::findOrFail($id) : null;
        // CU03 administra personal. CU23 administra el alta de cuentas de pacientes.
        abort_if($record?->role === 'Paciente', 403, 'La cuenta está vinculada al módulo de pacientes.');
        abort_if($record?->role === 'Superadmin' && $this->user()->role !== 'Superadmin', 403);

        return [
            'name' => 'required|string|max:150',
            'username' => ['sometimes', 'required', 'string', 'min:3', 'max:50', 'regex:'.Username::PATTERN, Rule::unique('users', 'username')->ignore($id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($id)],
            'password' => [$id ? 'nullable' : 'required', 'string', 'min:10', 'max:128'],
            'role' => ['required', Rule::in($this->user()->role === 'Superadmin'
                ? ['Superadmin', 'Administrador', 'Recepcionista', 'Médico'] : ['Administrador', 'Recepcionista', 'Médico'])],
            'status' => ['required', Rule::in(['Activo', 'Inactivo'])],
            'doctor_id' => ['nullable', 'integer', 'required_if:role,Médico', 'prohibited_unless:role,Médico',
                Rule::exists('doctors', 'id')->where(fn ($q) => $q->where('status', 'Activo')->orWhere('id', $record?->doctor_id)),
                Rule::unique('users')->ignore($id)],
        ];
    }
}
