<?php

namespace App\Http\Requests\Patients;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'Paciente';
    }

    public function rules(): array
    {
        return ['phone' => 'required|string|max:30', 'address' => 'nullable|string|max:1000'];
    }
}
