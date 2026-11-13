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
    public function ejecutarAccion(Request $peticion, int $id, string $accion, AppointmentService $servicio)
    {
        abort_unless(in_array($accion, ['cancel', 'attendance', 'absent', 'attend', 'missed-call']), 404);
        abort_unless(in_array($accion, ['attend', 'missed-call']) ? in_array($peticion->user()->role, ['Médico', 'Superadmin'], true) : $peticion->user()->hasStaffRole('Recepcionista'), 403);
        $criterios = $peticion->validate(['reason' => [in_array($accion, ['cancel', 'absent']) ? 'required' : 'nullable', 'string', 'max:3000'], 'cause' => 'nullable|in:patient,clinic', 'definitive' => 'nullable|boolean', 'consultation_finished' => 'nullable|boolean', 'followup_suggested_at' => 'nullable|date|after:now', 'followup_note' => 'nullable|string|max:3000'] + PaymentVerifier::INPUT);

        $cita = $servicio->cambiarEstado($id, $accion, $peticion->user(), $criterios['reason'] ?? null, $criterios);
        if ($peticion->user()->role === 'Médico') {
            $this->limitarDatosMedico($cita);
        }

        return $cita;
    }

    /** Retira datos de contacto y de pago que no necesita la atención médica. */
    private function limitarDatosMedico(Appointment $cita): void
    {
        $cita->unsetRelation('payment');
        $cita->makeHidden(['payment_id', 'booking_request_id', 'created_by', 'clinical_slot_id', 'modality']);
        $cita->patient?->setVisible(['id', 'first_name', 'last_name']);
        $cita->doctor?->setVisible(['id', 'first_name', 'last_name']);
        $cita->specialty?->setVisible(['id', 'name']);
        $cita->events?->each(fn ($evento) => $evento->setVisible(['id', 'appointment_id', 'action', 'details', 'created_at']));
    }
}
