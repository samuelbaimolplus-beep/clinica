<?php

/** Entrada HTTP de ControladorPagos. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P07_ReservasPagosYSeguimientos\CU19_VerificarPagoOExencion\Http;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Payment;
use Illuminate\Http\Request;

class ControladorPagos extends Controller
{
    /** Consulta pagos y exenciones para supervisión, sin revelar credenciales del proveedor. */
    public function consultarPagos(Request $solicitud)
    {
        abort_unless($solicitud->user()->role === 'Superadmin', 403);

        $pagos = Payment::with('bookingRequest.patient:id,first_name,last_name', 'appointments.patient:id,first_name,last_name')->orderByDesc('id')->paginate(50)->toArray();
        $pagos['exemptions'] = Appointment::where('complimentary', true)->with('patient:id,first_name,last_name')
            ->orderByDesc('id')->limit(50)->get(['id', 'patient_id', 'parent_appointment_id', 'date', 'start_time', 'status']);

        return $pagos;
    }
}
