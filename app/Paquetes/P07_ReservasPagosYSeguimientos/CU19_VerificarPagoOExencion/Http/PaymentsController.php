<?php

/** Entrada HTTP de PaymentsController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P07_ReservasPagosYSeguimientos\CU19_VerificarPagoOExencion\Http;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentsController extends Controller
{
    /** Consulta pagos y exenciones para supervisión, sin revelar credenciales del proveedor. */
    public function payments(Request $request)
    {
        abort_unless($request->user()->role === 'Superadmin', 403);

        $payments = Payment::with('bookingRequest.patient:id,first_name,last_name', 'appointments.patient:id,first_name,last_name')->orderByDesc('id')->paginate(50)->toArray();
        $payments['exemptions'] = Appointment::where('complimentary', true)->with('patient:id,first_name,last_name')
            ->orderByDesc('id')->limit(50)->get(['id', 'patient_id', 'parent_appointment_id', 'date', 'start_time', 'status']);

        return $payments;
    }
}
