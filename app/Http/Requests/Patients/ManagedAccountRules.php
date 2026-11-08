<?php

namespace App\Http\Requests\Patients;

use App\Support\Username;
use Illuminate\Validation\Rule;

trait ManagedAccountRules
{
    private function normalizeAccountCredentials(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'username' => mb_strtolower(trim((string) $this->input('username'))),
        ]);
    }

    private function accountRules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:'.Username::PATTERN, Rule::unique('users', 'username')],
            'password' => 'required|string|min:10|max:128|confirmed',
            'identity_verified' => 'required|accepted',
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Ya existe una cuenta con este correo electrónico.',
            'username.unique' => 'Este nombre de usuario ya está ocupado.',
            'identity_verified.accepted' => 'Confirme la identidad y el correo del paciente para habilitar su acceso.',
        ];
    }
}
