<?php

/** Contrato de entrada de ReglasPersona. Rechaza valores inválidos antes de ejecutar reglas de negocio. */

namespace App\Compartido\Catalogos\Validacion;

use App\Paquetes\P02_PersonalMedicoYEspecialidades\CU04_GestionarMedicos\Validacion\SolicitudGuardarMedico;
use App\Paquetes\P02_PersonalMedicoYEspecialidades\CU05_GestionarEspecialidades\Validacion\SolicitudGuardarEspecialidad;
use App\Paquetes\P03_Pacientes\CU06_GestionarPacientes\Validacion\SolicitudGuardarPaciente;
use App\Support\SpecialtyName;
use Illuminate\Validation\Rule;

trait ReglasPersona
{
    /** Construye las reglas reutilizadas por fichas de personas. */
    protected function reglasPersona(): array
    {
        return ['first_name' => 'required|string|max:100', 'last_name' => 'required|string|max:150',
            'phone' => 'required|string|max:30', 'status' => ['required', Rule::in(['Activo', 'Inactivo'])]];
    }

    /** Normaliza las entradas antes de aplicar reglas; no concede permisos ni omite validaciones. */
    protected function prepareForValidation(): void
    {
        foreach (['name', 'first_name', 'last_name', 'email', 'username', 'identity', 'registration'] as $campo) {
            if (is_string($this->input($campo))) {
                $valor = trim($this->input($campo));
                $valor = match ($campo) {
                    'name' => $this instanceof SolicitudGuardarEspecialidad ? SpecialtyName::display($valor) : $valor,
                    'email', 'username' => mb_strtolower($valor),
                    'identity', 'registration' => mb_strtoupper($valor),
                    default => $valor,
                };
                $this->merge([$campo => $valor === '' ? null : $valor]);
            }
        }
        if (! $this->route('id') && ($this instanceof SolicitudGuardarMedico || $this instanceof SolicitudGuardarEspecialidad || $this instanceof SolicitudGuardarPaciente)) {
            $this->merge(['status' => 'Activo']);
        }
    }
}
