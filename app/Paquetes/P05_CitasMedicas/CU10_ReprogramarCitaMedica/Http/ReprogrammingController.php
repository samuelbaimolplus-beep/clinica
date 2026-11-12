<?php

/** Solicitudes y acuerdos de reprogramación; conserva la cita vigente hasta resolver el cambio. */

namespace App\Paquetes\P05_CitasMedicas\CU10_ReprogramarCitaMedica\Http;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Paquetes\P05_CitasMedicas\Compartido\Servicios\AppointmentService;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU19_VerificarPagoOExencion\Servicios\PaymentVerifier;
use Illuminate\Http\Request;
use App\Models\RescheduleRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReprogrammingController extends Controller
{
    /** Aplica el nuevo horario acordado después de validar aceptación, cupo y pago. */
    public function reschedule(Request $r, int $id, AppointmentService $service)
    {
        abort_unless($r->user()->hasStaffRole('Recepcionista'), 403);
        $d = $r->validate(['doctor_id' => 'required|integer|exists:doctors,id', 'date' => 'required|date_format:Y-m-d|after_or_equal:today', 'start_time' => 'required|date_format:H:i', 'cause' => 'required|in:patient,clinic', 'patient_accepted' => 'required|accepted', 'change_reason' => 'required|string|max:3000'] + PaymentVerifier::INPUT);

        return $service->book($d, $r->user(), $id);
    }

    /** Consulta solicitudes y respuestas dentro del ámbito autorizado. */
    public function rescheduleList(Request $r)
    {
        abort_unless(in_array($r->user()->role, ['Superadmin', 'Recepcionista', 'Paciente']), 403);

        return RescheduleRequest::with('appointment.patient', 'appointment.doctor', 'appointment.specialty', 'appointment.payment')
            ->when($r->user()->role === 'Paciente', fn ($q) => $q->where('created_by', $r->user()->id))->latest()->get();
    }

    /** Registra la petición del paciente conservando la cita original. */
    public function requestReschedule(Request $r, int $id)
    {
        abort_unless($r->user()->role === 'Paciente', 403);
        $data = $r->validate(['reason' => 'required|string|max:3000']);

        return DB::transaction(function () use ($r, $id, $data) {
            $a = Appointment::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($a->patient_id === $r->user()->patient_id, 403);
            if (! in_array($a->status, Appointment::ACTIVE)) {
                throw ValidationException::withMessages(['appointment' => 'La cita ya no admite reprogramación.']);
            }
            if (RescheduleRequest::where('appointment_id', $id)->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['appointment' => 'Ya existe una solicitud pendiente.']);
            }

            return response()->json(RescheduleRequest::create(['appointment_id' => $id, 'created_by' => $r->user()->id, 'reason' => $data['reason']]), 201);
        });
    }

    /** Guarda una respuesta motivada y conserva la cita vigente. */
    public function rejectReschedule(Request $r, int $id)
    {
        abort_unless($r->user()->hasStaffRole('Recepcionista'), 403);
        $data = $r->validate(['response' => 'required|string|max:3000']);

        return DB::transaction(function () use ($r, $id, $data) {
            $request = RescheduleRequest::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['request' => 'La solicitud ya fue resuelta.']);
            }
            $request->update(['status' => 'rejected', 'response' => $data['response'], 'resolved_by' => $r->user()->id, 'resolved_at' => now()]);

            return $request;
        });
    }
}
