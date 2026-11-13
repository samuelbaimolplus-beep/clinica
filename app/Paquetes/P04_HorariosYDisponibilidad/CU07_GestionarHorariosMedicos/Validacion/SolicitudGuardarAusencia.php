<?php

/** Contrato de entrada de SolicitudGuardarAusencia. Rechaza valores inválidos antes de ejecutar reglas de negocio. */

namespace App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Validacion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SolicitudGuardarAusencia extends FormRequest
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
            'starts_at' => 'required|date_format:Y-m-d\TH:i', 'ends_at' => 'required|date_format:Y-m-d\TH:i|after:starts_at',
            'reason' => 'required|string|max:300'];
    }
}
