<?php

/** Solicitudes y acuerdos de reprogramación; conserva la cita vigente hasta resolver el cambio. */

namespace App\Paquetes\P05_CitasMedicas\CU10_ReprogramarCitaMedica\Http;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\RescheduleRequest;
use App\Paquetes\P05_CitasMedicas\Compartido\Servicios\AppointmentService;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU19_VerificarPagoOExencion\Servicios\PaymentVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReprogrammingController extends Controller
{
    /** Aplica el nuevo horario acordado después de validar aceptación, cupo y pago. */
    public function reprogramar(Request $peticion, int $id, AppointmentService $servicio)
    {
        abort_unless($peticion->user()->hasStaffRole('Recepcionista'), 403);
        $criterios = $peticion->validate(['doctor_id' => 'required|integer|exists:doctors,id', 'date' => 'required|date_format:Y-m-d|after_or_equal:today', 'start_time' => 'required|date_format:H:i', 'cause' => 'required|in:patient,clinic', 'patient_accepted' => 'required|accepted', 'change_reason' => 'required|string|max:3000'] + PaymentVerifier::INPUT);

        return $servicio->programar($criterios, $peticion->user(), $id);
    }

    /** Consulta solicitudes y respuestas dentro del ámbito autorizado. */
    public function listarSolicitudesCambio(Request $peticion)
    {
        abort_unless(in_array($peticion->user()->role, ['Superadmin', 'Recepcionista', 'Paciente']), 403);

        return RescheduleRequest::with('appointment.patient', 'appointment.doctor', 'appointment.specialty', 'appointment.payment')
            ->when($peticion->user()->role === 'Paciente', fn ($consulta) => $consulta->where('created_by', $peticion->user()->id))->latest()->get();
    }

    /** Registra la petición del paciente conservando la cita original. */
    public function solicitarReprogramacion(Request $peticion, int $id)
    {
        abort_unless($peticion->user()->role === 'Paciente', 403);
        $datos = $peticion->validate(['reason' => 'required|string|max:3000']);

        return DB::transaction(function () use ($peticion, $id, $datos) {
            $citaActual = Appointment::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($citaActual->patient_id === $peticion->user()->patient_id, 403);
            if (! in_array($citaActual->status, Appointment::ACTIVE)) {
                throw ValidationException::withMessages(['appointment' => 'La cita ya no admite reprogramación.']);
            }
            if (RescheduleRequest::where('appointment_id', $id)->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['appointment' => 'Ya existe una solicitud pendiente.']);
            }

            return response()->json(RescheduleRequest::create(['appointment_id' => $id, 'created_by' => $peticion->user()->id, 'reason' => $datos['reason']]), 201);
        });
    }

    /** Guarda una respuesta motivada y conserva la cita vigente. */
    public function rechazarReprogramacion(Request $peticion, int $id)
    {
        abort_unless($peticion->user()->hasStaffRole('Recepcionista'), 403);
        $datos = $peticion->validate(['response' => 'required|string|max:3000']);

        return DB::transaction(function () use ($peticion, $id, $datos) {
            $solicitud = RescheduleRequest::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($solicitud->status !== 'pending') {
                throw ValidationException::withMessages(['request' => 'La solicitud ya fue resuelta.']);
            }
            $solicitud->update(['status' => 'rejected', 'response' => $datos['response'], 'resolved_by' => $peticion->user()->id, 'resolved_at' => now()]);

            return $solicitud;
        });
    }
}
