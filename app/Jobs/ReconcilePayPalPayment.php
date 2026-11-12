<?php

/** Componente ReconcilePayPalPayment: colabora con los módulos del sistema sin reemplazar sus controles del servidor. */

namespace App\Jobs;

use App\Models\BookingRequest;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU18_ReservarCitaOnline\Servicios\OnlineBookingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcilePayPalPayment implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Recibe las dependencias o datos que necesita esta instancia. */
    public function __construct(public int $bookingId) {}

    /** Ejecuta la responsabilidad de este middleware, comando o trabajo; sus controles se explican en el encabezado de la clase. */
    public function handle(OnlineBookingService $service): void
    {
        $booking = BookingRequest::find($this->bookingId);
        if ($booking) {
            $service->reconcile($booking, true);
        }
    }
}
