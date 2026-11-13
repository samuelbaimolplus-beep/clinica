<?php

/** Contrato de entrada de CreatePatientAccountRequest. Rechaza valores inválidos antes de ejecutar reglas de negocio. */

namespace App\Paquetes\P01_UsuariosYAcceso\CU23_RegistrarCuentaDePaciente\Validacion;

use Illuminate\Foundation\Http\FormRequest;

class CreatePatientAccountRequest extends FormRequest
{
    use ManagedAccountRules;

    /** Normaliza las entradas antes de aplicar reglas; no concede permisos ni omite validaciones. */
    protected function prepareForValidation(): void
    {
        $this->normalizarCredenciales();
    }

    /** Decide si la solicitud puede continuar; complementa los controles de las rutas. */
    public function authorize(): bool
    {
        return $this->user()->can('manage-patient-accounts');
    }

    /** Define los campos aceptados, tipos, límites y relaciones válidas para esta solicitud. */
    public function rules(): array
    {
        return $this->reglasCuenta();
    }
}
