<?php

namespace App\Http\Requests\Patients;

use Illuminate\Foundation\Http\FormRequest;

class CreatePatientAccountRequest extends FormRequest
{
    use ManagedAccountRules;

    protected function prepareForValidation(): void
    {
        $this->normalizeAccountCredentials();
    }

    public function authorize(): bool
    {
        return $this->user()->can('manage-patient-accounts');
    }

    public function rules(): array
    {
        return $this->accountRules();
    }
}
