<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Catalog\PersonRules;
use App\Support\Username;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterPatientRequest extends FormRequest
{
    use PersonRules;

    public function authorize(): bool
    {
        return $this->user() === null;
    }

    public function rules(): array
    {
        return [
            'first_name' => 'required|string|max:100', 'last_name' => 'required|string|max:150',
            'phone' => 'required|string|max:30',
            'identity' => ['nullable', 'string', 'max:40', ...($this->filled('activation_code') ? [] : [Rule::unique('patients')])],
            'activation_code' => 'nullable|string|size:48',
            'birth_date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'sex' => ['required', Rule::in(['Femenino', 'Masculino'])],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')],
            'username' => ['sometimes', 'required', 'string', 'min:3', 'max:50', 'regex:'.Username::PATTERN, Rule::unique('users', 'username')],
            'password' => 'required|string|min:10|max:128|confirmed',
            'address' => 'nullable|string|max:1000',
            'terms_accepted' => 'required|accepted',
            'terms_version' => ['required', Rule::in([config('clinic.terms_version')])],
        ];
    }

    public function messages(): array
    {
        return ['identity.unique' => 'No se puede crear una cuenta con esos datos. Si ya fue registrado, solicite verificación en recepción.',
            'terms_version.in' => 'Los términos cambiaron. Recargue la página y revise la versión vigente.'];
    }
}
