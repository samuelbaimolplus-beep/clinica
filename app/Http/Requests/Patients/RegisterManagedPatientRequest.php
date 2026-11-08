<?php

namespace App\Http\Requests\Patients;

use App\Http\Requests\Catalog\SavePatientRequest;

class RegisterManagedPatientRequest extends SavePatientRequest
{
    use ManagedAccountRules;

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->normalizeAccountCredentials();
    }

    public function authorize(): bool
    {
        return $this->user()->can('manage-patient-accounts');
    }

    public function rules(): array
    {
        return array_replace(parent::rules(), $this->accountRules());
    }
}
