<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSpecialtyRequest extends FormRequest
{
    use PersonRules;

    public function authorize(): bool
    {
        return $this->user()->can('manage-specialties');
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:120', Rule::unique('specialties')->ignore($this->route('id'))],
            'description' => 'nullable|string|max:3000', 'fee_amount' => ['sometimes', 'nullable', 'regex:/^\d{1,6}(\.\d{1,2})?$/', 'numeric', 'min:0.01'], 'status' => ['required', Rule::in(['Activo', 'Inactivo'])]];
    }
}
