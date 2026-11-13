<?php

/** Entrada HTTP de AppointmentController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P05_CitasMedicas\CU09_ProgramarCitaMedica\Http;

use App\Http\Controllers\Controller;
use App\Paquetes\P05_CitasMedicas\Compartido\Servicios\AppointmentService;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU19_VerificarPagoOExencion\Servicios\PaymentVerifier;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    /** Registra la operación solicitada después de validar entradas y permisos. */
    public function registrar(Request $peticion, AppointmentService $servicio)
    {
        abort_unless($peticion->user()->hasStaffRole('Recepcionista'), 403);
        $criterios = $peticion->validate(['patient_id' => 'required|integer|exists:patients,id', 'doctor_id' => 'required|integer|exists:doctors,id', 'specialty_id' => 'required|integer|exists:specialties,id', 'date' => 'required|date_format:Y-m-d|after_or_equal:today', 'start_time' => 'required|date_format:H:i', 'reason' => 'nullable|string|max:3000', 'idempotency_key' => 'required|uuid', 'parent_appointment_id' => 'nullable|integer|exists:appointments,id', 'followup_change_cause' => 'nullable|in:patient,clinic', 'followup_change_reason' => 'nullable|string|max:3000'] + PaymentVerifier::INPUT);

        return response()->json($servicio->programar($criterios, $peticion->user()), 201);
    }
}
