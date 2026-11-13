<?php

/** Contrato de entrada de SolicitudGuardarHorario. Rechaza valores inválidos antes de ejecutar reglas de negocio. */

namespace App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Validacion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SolicitudGuardarHorario extends FormRequest
{
    /** Decide si la solicitud puede continuar; complementa los controles de las rutas. */
    public function authorize(): bool
    {
        return $this->user()->can('manage-schedules');
    }

    /** Define los campos aceptados, tipos, límites y relaciones válidas para esta solicitud. */
    public function rules(): array
    {
        return ['doctor_id' => ['required', 'integer', Rule::exists('doctors', 'id')->where('status', 'Activo')],
            'weekday' => 'required|integer|between:0,6', 'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time', 'duration' => 'required|integer|min:1'];
    }
}
