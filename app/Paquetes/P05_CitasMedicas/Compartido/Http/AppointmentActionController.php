<?php

/** Autoriza transiciones de una cita; el servicio comprueba fecha, pago y estado antes de guardarlas. */

namespace App\Paquetes\P05_CitasMedicas\Compartido\Http;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Paquetes\P05_CitasMedicas\Compartido\Servicios\AppointmentService;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU19_VerificarPagoOExencion\Servicios\PaymentVerifier;
use Illuminate\Http\Request;

class AppointmentActionController extends Controller
{
    /** Autoriza la acción clínica solicitada y devuelve el estado confirmado por el servicio. */
    public function action(Request $r, int $id, string $action, AppointmentService $service)
    {
        abort_unless(in_array($action, ['cancel', 'attendance', 'absent', 'attend', 'missed-call']), 404);
        abort_unless(in_array($action, ['attend', 'missed-call']) ? in_array($r->user()->role, ['Médico', 'Superadmin'], true) : $r->user()->hasStaffRole('Recepcionista'), 403);
        $d = $r->validate(['reason' => [in_array($action, ['cancel', 'absent']) ? 'required' : 'nullable', 'string', 'max:3000'], 'cause' => 'nullable|in:patient,clinic', 'definitive' => 'nullable|boolean', 'consultation_finished' => 'nullable|boolean', 'followup_suggested_at' => 'nullable|date|after:now', 'followup_note' => 'nullable|string|max:3000'] + PaymentVerifier::INPUT);

        $appointment = $service->transition($id, $action, $r->user(), $d['reason'] ?? null, $d);
        if ($r->user()->role === 'Médico') {
            $this->restrictPhysicianData($appointment);
        }

        return $appointment;
    }

    /** Retira datos de contacto y de pago que no necesita la atención médica. */
    private function restrictPhysicianData(Appointment $appointment): void
    {
        $appointment->unsetRelation('payment');
        $appointment->makeHidden(['payment_id', 'booking_request_id', 'created_by', 'clinical_slot_id', 'modality']);
        $appointment->patient?->setVisible(['id', 'first_name', 'last_name']);
        $appointment->doctor?->setVisible(['id', 'first_name', 'last_name']);
        $appointment->specialty?->setVisible(['id', 'name']);
        $appointment->events?->each(fn ($event) => $event->setVisible(['id', 'appointment_id', 'action', 'details', 'created_at']));
    }
}
