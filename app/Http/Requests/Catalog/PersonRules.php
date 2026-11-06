<?php

namespace App\Http\Requests\Catalog;

use App\Support\SpecialtyName;
use Illuminate\Validation\Rule;

trait PersonRules
{
    protected function personRules(): array
    {
        return ['first_name' => 'required|string|max:100', 'last_name' => 'required|string|max:150',
            'phone' => 'required|string|max:30', 'status' => ['required', Rule::in(['Activo', 'Inactivo'])]];
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'first_name', 'last_name', 'email', 'identity', 'registration'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $value = match ($field) {
                    'name' => $this instanceof SaveSpecialtyRequest ? SpecialtyName::display($value) : $value,
                    'email' => mb_strtolower($value),
                    'identity', 'registration' => mb_strtoupper($value),
                    default => $value,
                };
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }
        if (! $this->route('id') && ($this instanceof SaveDoctorRequest || $this instanceof SaveSpecialtyRequest)) {
            $this->merge(['status' => 'Activo']);
        }
    }
}
