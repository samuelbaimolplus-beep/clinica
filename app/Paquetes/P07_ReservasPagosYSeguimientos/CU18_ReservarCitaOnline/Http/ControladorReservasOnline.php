<?php

/** Entrada HTTP de ControladorReservasOnline. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P07_ReservasPagosYSeguimientos\CU18_ReservarCitaOnline\Http;

use App\Http\Controllers\Controller;
use App\Models\BookingRequest;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU18_ReservarCitaOnline\Servicios\ServicioReservasOnline;
use Illuminate\Http\Request;

class ControladorReservasOnline extends Controller
{
    /** Inicia la reserva online del paciente autenticado. */
    public function iniciarReservaOnline(Request $peticion, ServicioReservasOnline $servicio)
    {
        abort_unless($peticion->user()->role === 'Paciente', 403);
        $datos = $peticion->validate(['doctor_id' => 'required|integer|exists:doctors,id', 'specialty_id' => 'required|integer|exists:specialties,id',
            'date' => 'required|date_format:Y-m-d|after_or_equal:today', 'start_time' => 'required|date_format:H:i', 'idempotency_key' => 'required|uuid']);

        return $this->respuestaReservaOnline($servicio->iniciar($datos, $peticion->user()));
    }

    /** Concilia el estado de la reserva con el proveedor desde el servidor. */
    public function consultarEstadoOnline(Request $peticion, int $id, ServicioReservasOnline $servicio)
    {
        return $this->respuestaReservaOnline($servicio->conciliar($this->reservaPropia($peticion, $id)));
    }

    /** Muestra una operación online autorizada sin suplantar al titular. */
    public function consultarReservaOnline(Request $peticion, int $id)
    {
        $reserva = BookingRequest::findOrFail($id);
        abort_unless($peticion->user()->role === 'Superadmin' || ($peticion->user()->role === 'Paciente' && $reserva->created_by === $peticion->user()->id), 403);

        return $this->respuestaReservaOnline($reserva);
    }

    /** Inicia el siguiente intento permitido después de comprobar el estado del anterior. */
    public function reintentarPagoOnline(Request $peticion, int $id, ServicioReservasOnline $servicio)
    {
        $reserva = $servicio->conciliar($this->reservaPropia($peticion, $id));

        return $this->respuestaReservaOnline($servicio->intentarPago($reserva));
    }

    /** Descarta una solicitud únicamente si el servidor confirma que no hay cobro en proceso. */
    public function descartarReservaOnline(Request $peticion, int $id, ServicioReservasOnline $servicio)
    {
        return $this->respuestaReservaOnline($servicio->descartar($this->reservaPropia($peticion, $id)));
    }

    /** Resuelve una reserva dentro del ámbito del titular autenticado. */
    private function reservaPropia(Request $peticion, int $id): BookingRequest
    {
        $reserva = BookingRequest::findOrFail($id);
        abort_unless($peticion->user()->role === 'Paciente' && $reserva->created_by === $peticion->user()->id, 403);

        return $reserva;
    }

    /** Compone el resultado de reserva, pago y cita confirmada cuando existe. */
    private function respuestaReservaOnline(BookingRequest $reserva): array
    {
        $pago = $reserva->payments()->latest('attempt')->first();

        return ['booking' => $reserva, 'payment' => $pago,
            'approval_url' => $pago?->method === 'paypal' ? $pago->approval_url : null,
            'appointment' => $reserva->appointment()->with('doctor', 'specialty', 'patient', 'payment')->first()];
    }

    /** Lista reservas dentro del ámbito del usuario. */
    public function listarReservasOnline(Request $peticion)
    {
        abort_unless(in_array($peticion->user()->role, ['Superadmin', 'Paciente'], true), 403);

        return BookingRequest::when($peticion->user()->role === 'Paciente', fn ($consulta) => $consulta->where('created_by', $peticion->user()->id))
            ->where('modality', 'online')->with('payments', 'patient:id,first_name,last_name', 'doctor:id,first_name,last_name', 'specialty:id,name')->latest()
            ->when($peticion->user()->role === 'Paciente', fn ($consulta) => $consulta->limit(50))->get();
    }
}
