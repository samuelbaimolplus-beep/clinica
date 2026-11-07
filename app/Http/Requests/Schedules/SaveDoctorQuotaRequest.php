<?php

namespace App\Http\Requests\Schedules;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveDoctorQuotaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-schedules');
    }

    public function rules(): array
    {
        $percent = ['required', 'integer', 'between:0,100'];

        return ['doctor_id' => ['required', 'integer', Rule::exists('doctors', 'id')->where('status', 'Activo')],
            'presential_percent' => $percent, 'online_percent' => $percent];
    }
}
