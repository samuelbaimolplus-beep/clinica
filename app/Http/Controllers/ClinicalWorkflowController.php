<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\BookingRequest;
use App\Models\RefundRequest;
use App\Models\RescheduleRequest;
use App\Services\Payments\OnlineBookingService;
use App\Services\Payments\RefundService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicalWorkflowController extends Controller
{
    public function onlineStart(Request $r, OnlineBookingService $service)
    {
        abort_unless($r->user()->role === 'Paciente', 403);
        $data = $r->validate(['doctor_id' => 'required|integer|exists:doctors,id', 'specialty_id' => 'required|integer|exists:specialties,id',
            'date' => 'required|date_format:Y-m-d|after_or_equal:today', 'start_time' => 'required|date_format:H:i', 'idempotency_key' => 'required|uuid']);

        return $this->onlineResponse($service->start($data, $r->user()));
    }

    public function onlineStatus(Request $r, int $id, OnlineBookingService $service)
    {
        return $this->onlineResponse($service->reconcile($this->ownedBooking($r, $id)));
    }

    public function onlineRetry(Request $r, int $id, OnlineBookingService $service)
    {
        $booking = $service->reconcile($this->ownedBooking($r, $id));

        return $this->onlineResponse($service->attempt($booking));
    }

    private function ownedBooking(Request $r, int $id): BookingRequest
    {
        $booking = BookingRequest::findOrFail($id);
        abort_unless($r->user()->role === 'Paciente' && $booking->created_by === $r->user()->id, 403);

        return $booking;
    }

    private function onlineResponse(BookingRequest $booking): array
    {
        $payment = $booking->payments()->latest('attempt')->first();

        return ['booking' => $booking, 'payment' => $payment, 'qr_image' => $payment?->qr_image,
            'appointment' => $booking->appointment()->with('doctor', 'specialty', 'patient', 'payment')->first()];
    }

    public function onlineList(Request $r)
    {
        abort_unless($r->user()->role === 'Paciente', 403);

        return BookingRequest::where('created_by', $r->user()->id)->where('modality', 'online')->with('payments')->latest()->limit(50)->get();
    }

    public function rescheduleList(Request $r)
    {
        abort_unless(in_array($r->user()->role, ['Recepcionista', 'Paciente']), 403);

        return RescheduleRequest::with('appointment.patient', 'appointment.doctor', 'appointment.specialty', 'appointment.payment')
            ->when($r->user()->role === 'Paciente', fn ($q) => $q->where('created_by', $r->user()->id))->latest()->get();
    }

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

    public function rejectReschedule(Request $r, int $id)
    {
        abort_unless($r->user()->role === 'Recepcionista', 403);
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

    public function refunds(Request $r)
    {
        abort_unless(in_array($r->user()->role, ['Administrador', 'Recepcionista', 'Paciente']), 403);

        return RefundRequest::with('appointment.patient', 'appointment.doctor', 'appointment.specialty', 'payment')
            ->when($r->user()->role === 'Paciente', fn ($q) => $q->whereHas('appointment', fn ($q) => $q->where('patient_id', $r->user()->patient_id)))->latest()->get();
    }

    public function requestRefund(Request $r, int $id, RefundService $service)
    {
        $data = $r->validate(['reason' => 'required|string|max:3000']);

        return response()->json($service->request($id, $data['reason'], $r->user()), 201);
    }

    public function decideRefund(Request $r, int $id, RefundService $service)
    {
        abort_unless($r->user()->role === 'Administrador', 403);
        $data = $r->validate(['decision' => 'required|in:approve,reject', 'reason' => 'required_if:decision,reject|nullable|string|max:3000']);

        return $service->decide($id, $data, $r->user());
    }

    public function completeRefund(Request $r, int $id, RefundService $service)
    {
        abort_unless($r->user()->role === 'Administrador', 403);
        $data = $r->validate(['executor' => 'required|string|max:255', 'method' => 'required|string|max:80', 'reference' => 'nullable|string|max:255',
            'amount_minor' => 'required|integer|min:1', 'completed_at' => 'required|date|before_or_equal:now']);

        return $service->complete($id, $data, $r->user());
    }
}
