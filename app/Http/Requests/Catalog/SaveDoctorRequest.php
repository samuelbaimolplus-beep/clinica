<?php

namespace App\Http\Requests\Catalog;

use App\Models\Doctor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveDoctorRequest extends FormRequest
{
    use PersonRules;

    public function authorize(): bool
    {
        return $this->user()->can('manage-doctors');
    }

    public function rules(): array
    {
        $id = $this->route('id');
        if ($id) {
            Doctor::findOrFail($id);
        }

        return $this->personRules() + [
            'registration' => ['required', 'string', 'max:80', Rule::unique('doctors')->ignore($id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('doctors')->ignore($id)],
            'specialty_ids' => 'required|array|min:1',
            'specialty_ids.*' => ['integer', 'distinct', Rule::exists('specialties', 'id')->where('status', 'Activo')],
        ];
    }
}
