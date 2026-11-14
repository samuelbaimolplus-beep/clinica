<?php

/** Contrato de entrada de ReglasCuentaPaciente. Rechaza valores inválidos antes de ejecutar reglas de negocio. */

namespace App\Paquetes\P01_UsuariosYAcceso\CU23_RegistrarCuentaDePaciente\Validacion;

use App\Support\NombreUsuario;
use Illuminate\Validation\Rule;

trait ReglasCuentaPaciente
{
    /** Normaliza correo y nombre de usuario antes de comprobar formato y unicidad. */
    private function normalizarCredenciales(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'username' => mb_strtolower(trim((string) $this->input('username'))),
        ]);
    }

    /** Construye el contrato de una cuenta administrada presencialmente. */
    private function reglasCuenta(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:'.NombreUsuario::PATTERN, Rule::unique('users', 'username')],
            'password' => 'required|string|min:10|max:128|confirmed',
            'identity_verified' => 'required|accepted',
            'terms_accepted' => 'required|accepted',
            'terms_version' => ['required', Rule::in([config('clinic.terms_version')])],
        ];
    }

    /** Presenta mensajes comprensibles para los errores de validación de este formulario. */
    public function messages(): array
    {
        return [
            'email.unique' => 'Ya existe una cuenta con este correo electrónico.',
            'username.unique' => 'Este nombre de usuario ya está ocupado.',
            'identity_verified.accepted' => 'Confirme la identidad y el correo del paciente para habilitar su acceso.',
            'terms_accepted.required' => 'La cuenta es opcional: confirme que el paciente adulto la solicitó y aceptó los términos.',
            'terms_accepted.accepted' => 'Sin aceptación de los términos solo se registra la ficha asistencial, sin cuenta del portal.',
            'terms_version.required' => 'Debe registrar la versión de términos aceptada por el paciente.',
            'terms_version.in' => 'Los términos cambiaron. Recargue la pantalla y revise la versión vigente con el paciente.',
        ];
    }
}
