<?php

/** Contrato de entrada de SaveDoctorQuotaRequest. Rechaza valores inválidos antes de ejecutar reglas de negocio. */

namespace App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Validacion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveDoctorQuotaRequest extends FormRequest
{
    /** Decide si la solicitud puede continuar; complementa los controles de las rutas. */
    public function authorize(): bool
    {
        return $this->user()->can('manage-schedules');
    }

    /** Define los campos aceptados, tipos, límites y relaciones válidas para esta solicitud. */
    public function rules(): array
    {
        $porcentaje = ['required', 'integer', 'between:0,100'];

        return ['doctor_id' => ['required', 'integer', Rule::exists('doctors', 'id')->where('status', 'Activo')],
            'presential_percent' => $porcentaje, 'online_percent' => $porcentaje];
    }
}
