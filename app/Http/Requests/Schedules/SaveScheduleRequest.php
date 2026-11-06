<?php

namespace App\Http\Requests\Schedules;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-schedules');
    }

    public function rules(): array
    {
        return ['doctor_id' => ['required', 'integer', Rule::exists('doctors', 'id')->where('status', 'Activo')],
            'weekday' => 'required|integer|between:0,6', 'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time', 'duration' => 'required|integer|between:5,240'];
    }
}
