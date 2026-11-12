<?php

/** Entrada HTTP de OnlineBookingController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P07_ReservasPagosYSeguimientos\CU18_ReservarCitaOnline\Http;

use App\Http\Controllers\Controller;
use App\Models\BookingRequest;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU18_ReservarCitaOnline\Servicios\OnlineBookingService;
use Illuminate\Http\Request;

class OnlineBookingController extends Controller
{
    /** Inicia la reserva online del paciente autenticado. */
    public function onlineStart(Request $r, OnlineBookingService $service)
    {
        abort_unless($r->user()->role === 'Paciente', 403);
        $data = $r->validate(['doctor_id' => 'required|integer|exists:doctors,id', 'specialty_id' => 'required|integer|exists:specialties,id',
            'date' => 'required|date_format:Y-m-d|after_or_equal:today', 'start_time' => 'required|date_format:H:i', 'idempotency_key' => 'required|uuid']);

        return $this->onlineResponse($service->start($data, $r->user()));
    }

    /** Concilia el estado de la reserva con el proveedor desde el servidor. */
    public function onlineStatus(Request $r, int $id, OnlineBookingService $service)
    {
        return $this->onlineResponse($service->reconcile($this->ownedBooking($r, $id)));
    }

    /** Muestra una operación online autorizada sin suplantar al titular. */
    public function onlineShow(Request $r, int $id)
    {
        $booking = BookingRequest::findOrFail($id);
        abort_unless($r->user()->role === 'Superadmin' || ($r->user()->role === 'Paciente' && $booking->created_by === $r->user()->id), 403);

        return $this->onlineResponse($booking);
    }

    /** Inicia el siguiente intento permitido después de comprobar el estado del anterior. */
    public function onlineRetry(Request $r, int $id, OnlineBookingService $service)
    {
        $booking = $service->reconcile($this->ownedBooking($r, $id));

        return $this->onlineResponse($service->attempt($booking));
    }

    /** Descarta una solicitud únicamente si el servidor confirma que no hay cobro en proceso. */
    public function onlineCancel(Request $r, int $id, OnlineBookingService $service)
    {
        return $this->onlineResponse($service->cancel($this->ownedBooking($r, $id)));
    }

    /** Resuelve una reserva dentro del ámbito del titular autenticado. */
    private function ownedBooking(Request $r, int $id): BookingRequest
    {
        $booking = BookingRequest::findOrFail($id);
        abort_unless($r->user()->role === 'Paciente' && $booking->created_by === $r->user()->id, 403);

        return $booking;
    }

    /** Compone el resultado de reserva, pago y cita confirmada cuando existe. */
    private function onlineResponse(BookingRequest $booking): array
    {
        $payment = $booking->payments()->latest('attempt')->first();

        return ['booking' => $booking, 'payment' => $payment,
            'approval_url' => $payment?->method === 'paypal' ? $payment->approval_url : null,
            'appointment' => $booking->appointment()->with('doctor', 'specialty', 'patient', 'payment')->first()];
    }

    /** Lista reservas dentro del ámbito del usuario. */
    public function onlineList(Request $r)
    {
        abort_unless(in_array($r->user()->role, ['Superadmin', 'Paciente'], true), 403);

        return BookingRequest::when($r->user()->role === 'Paciente', fn ($q) => $q->where('created_by', $r->user()->id))
            ->where('modality', 'online')->with('payments', 'patient:id,first_name,last_name', 'doctor:id,first_name,last_name', 'specialty:id,name')->latest()
            ->when($r->user()->role === 'Paciente', fn ($q) => $q->limit(50))->get();
    }
}
