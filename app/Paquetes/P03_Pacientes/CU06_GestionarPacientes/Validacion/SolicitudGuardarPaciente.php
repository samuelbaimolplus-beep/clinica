<?php

/** Contrato de entrada de SolicitudGuardarPaciente. Rechaza valores inválidos antes de ejecutar reglas de negocio. */

namespace App\Paquetes\P03_Pacientes\CU06_GestionarPacientes\Validacion;

use App\Compartido\Catalogos\Validacion\ReglasPersona;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SolicitudGuardarPaciente extends FormRequest
{
    use ReglasPersona;

    /** Decide si la solicitud puede continuar; complementa los controles de las rutas. */
    public function authorize(): bool
    {
        return $this->user()->can('manage-patients');
    }

    /** Define los campos aceptados, tipos, límites y relaciones válidas para esta solicitud. */
    public function rules(): array
    {
        return $this->reglasPersona() + [
            'identity' => ['nullable', 'string', 'max:40', Rule::unique('patients')->ignore($this->route('id'))],
            'birth_date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'sex' => ['required', Rule::in(['Femenino', 'Masculino'])],
            'email' => 'nullable|email|max:255', 'address' => 'nullable|string|max:1000',
        ];
    }
}
