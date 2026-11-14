<?php

/** Contrato de entrada de SolicitudGuardarUsuario. Rechaza valores inválidos antes de ejecutar reglas de negocio. */

namespace App\Paquetes\P01_UsuariosYAcceso\CU03_GestionarUsuariosYRoles\Validacion;

use App\Compartido\Catalogos\Validacion\ReglasPersona;
use App\Models\Usuario;
use App\Support\NombreUsuario;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SolicitudGuardarUsuario extends FormRequest
{
    use ReglasPersona;

    /** Decide si la solicitud puede continuar; complementa los controles de las rutas. */
    public function authorize(): bool
    {
        return $this->user()->can('manage-users');
    }

    /** Define los campos aceptados, tipos, límites y relaciones válidas para esta solicitud. */
    public function rules(): array
    {
        $id = $this->route('id');
        $registro = $id ? Usuario::findOrFail($id) : null;
        // CU03 administra personal. CU23 administra el alta de cuentas de pacientes.
        abort_if($registro?->role === 'Paciente', 403, 'La cuenta está vinculada al módulo de pacientes.');
        abort_if($registro?->role === 'Superadmin' && $this->user()->role !== 'Superadmin', 403);

        return [
            'name' => 'required|string|max:150',
            'birth_date' => 'required|date_format:Y-m-d|before_or_equal:'.today()->subYearsNoOverflow(18)->toDateString(),
            'username' => ['sometimes', 'required', 'string', 'min:3', 'max:50', 'regex:'.NombreUsuario::PATTERN, Rule::unique('users', 'username')->ignore($id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($id)],
            'password' => [$id ? 'nullable' : 'required', 'string', 'min:10', 'max:128'],
            'role' => ['required', Rule::in($this->user()->role === 'Superadmin'
                ? ['Superadmin', 'Administrador', 'Recepcionista', 'Médico'] : ['Administrador', 'Recepcionista', 'Médico'])],
            'status' => ['required', Rule::in(['Activo', 'Inactivo'])],
            'doctor_id' => ['nullable', 'integer', 'required_if:role,Médico', 'prohibited_unless:role,Médico',
                Rule::exists('doctors', 'id')->where(fn ($consulta) => $consulta->where('status', 'Activo')->orWhere('id', $registro?->doctor_id)),
                Rule::unique('users')->ignore($id)],
        ];
    }
}
