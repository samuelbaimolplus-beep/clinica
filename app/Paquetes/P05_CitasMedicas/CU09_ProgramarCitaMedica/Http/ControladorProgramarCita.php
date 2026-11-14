<?php

/** Entrada HTTP de ControladorProgramarCita. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P05_CitasMedicas\CU09_ProgramarCitaMedica\Http;

use App\Http\Controllers\Controller;
use App\Paquetes\P05_CitasMedicas\Compartido\Servicios\ServicioCitas;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU19_VerificarPagoOExencion\Servicios\VerificadorPago;
use Illuminate\Http\Request;

class ControladorProgramarCita extends Controller
{
    /** Registra la operación solicitada después de validar entradas y permisos. */
    public function registrar(Request $peticion, ServicioCitas $servicio)
    {
        abort_unless($peticion->user()->tieneRolPersonal('Recepcionista'), 403);
        $criterios = $peticion->validate(['patient_id' => 'required|integer|exists:patients,id', 'doctor_id' => 'required|integer|exists:doctors,id', 'specialty_id' => 'required|integer|exists:specialties,id', 'date' => 'required|date_format:Y-m-d|after_or_equal:today', 'start_time' => 'required|date_format:H:i', 'reason' => 'nullable|string|max:3000', 'idempotency_key' => 'required|uuid', 'parent_appointment_id' => 'nullable|integer|exists:appointments,id', 'followup_change_cause' => 'nullable|in:patient,clinic', 'followup_change_reason' => 'nullable|string|max:3000'] + VerificadorPago::INPUT);

        return response()->json($servicio->programar($criterios, $peticion->user()), 201);
    }
}
