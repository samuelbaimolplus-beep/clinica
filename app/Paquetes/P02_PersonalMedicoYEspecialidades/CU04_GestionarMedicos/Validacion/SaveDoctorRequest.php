<?php

/** Contrato de entrada de SaveDoctorRequest. Rechaza valores inválidos antes de ejecutar reglas de negocio. */

namespace App\Paquetes\P02_PersonalMedicoYEspecialidades\CU04_GestionarMedicos\Validacion;

use App\Compartido\Catalogos\Validacion\PersonRules;
use App\Models\Doctor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveDoctorRequest extends FormRequest
{
    use PersonRules;

    /** Decide si la solicitud puede continuar; complementa los controles de las rutas. */
    public function authorize(): bool
    {
        return $this->user()->can('manage-doctors');
    }

    /** Define los campos aceptados, tipos, límites y relaciones válidas para esta solicitud. */
    public function rules(): array
    {
        $id = $this->route('id');
        if ($id) {
            Doctor::findOrFail($id);
        }

        return $this->reglasPersona() + [
            'registration' => ['required', 'string', 'max:80', Rule::unique('doctors')->ignore($id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('doctors')->ignore($id)],
            'specialty_ids' => 'required|array|min:1',
            'specialty_ids.*' => ['integer', 'distinct', Rule::exists('specialties', 'id')->where('status', 'Activo')],
        ];
    }
}
