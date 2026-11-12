<?php

/** Contrato de entrada de SaveSpecialtyRequest. Rechaza valores inválidos antes de ejecutar reglas de negocio. */

namespace App\Paquetes\P02_PersonalMedicoYEspecialidades\CU05_GestionarEspecialidades\Validacion;

use App\Compartido\Catalogos\Validacion\PersonRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSpecialtyRequest extends FormRequest
{
    use PersonRules;

    /** Decide si la solicitud puede continuar; complementa los controles de las rutas. */
    public function authorize(): bool
    {
        return $this->user()->can('manage-specialties');
    }

    /** Define los campos aceptados, tipos, límites y relaciones válidas para esta solicitud. */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:120', Rule::unique('specialties')->ignore($this->route('id'))],
            'description' => 'nullable|string|max:3000', 'fee_amount' => ['sometimes', 'nullable', 'regex:/^\d{1,6}(\.\d{1,2})?$/', 'numeric', 'min:0.01'], 'status' => ['required', Rule::in(['Activo', 'Inactivo'])]];
    }
}
