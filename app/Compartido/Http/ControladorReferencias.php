<?php

/** Entrada HTTP de ControladorReferencias. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Compartido\Http;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Specialty;
use Illuminate\Http\Request;

class ControladorReferencias extends Controller
{
    /** Atiende la operación única de esta clase usando las dependencias resueltas por Laravel. */
    public function __invoke(Request $solicitud)
    {
        // RN12: el paciente nunca descarga padrones ni datos de otros pacientes.
        $rol = $solicitud->user()->role;
        if ($rol === 'Paciente') {
            return ['doctors' => [], 'specialties' => [], 'patients' => []];
        }

        return [
            'doctors' => Doctor::with('specialties')->when($rol === 'Médico', fn ($consulta) => $consulta->whereKey($solicitud->user()->doctor_id))->orderBy('last_name')->get(),
            'specialties' => Specialty::orderBy('name')->get(),
            'patients' => $solicitud->user()->can('manage-patients')
                ? Patient::orderBy('last_name')->get() : [],
        ];
    }
}
