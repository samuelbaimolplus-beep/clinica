<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePatientRequest extends FormRequest
{
    use PersonRules;

    public function authorize(): bool
    {
        return $this->user()->can('manage-patients');
    }

    public function rules(): array
    {
        return $this->personRules() + [
            'identity' => ['nullable', 'string', 'max:40', Rule::unique('patients')->ignore($this->route('id'))],
            'birth_date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'sex' => ['required', Rule::in(['Femenino', 'Masculino'])],
            'email' => 'nullable|email|max:255', 'address' => 'nullable|string|max:1000',
        ];
    }
}
