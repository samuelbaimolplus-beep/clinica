<?php

/** Puntos de entrada de console: agrupan operaciones y sus controles de acceso antes de llegar al dominio. */

use App\Models\SolicitudReserva;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU18_ReservarCitaOnline\Servicios\ServicioReservasOnline;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('clinica:conciliar-pagos', function () {
    $cantidad = 0;
    SolicitudReserva::whereIn('status', ['held', 'processing'])->where('modality', 'online')->orderBy('id')->chunkById(50, function ($reservas) use (&$cantidad) {
        foreach ($reservas as $booking) {
            app(ServicioReservasOnline::class)->conciliar($booking);
            $cantidad++;
        }
    });
    $this->info('Operaciones revisadas: '.$cantidad.'. Las transacciones no resueltas conservan su retención.');
})->purpose('Conciliar pagos sandbox y formalizar exclusivamente cobros confirmados');

Schedule::command('clinica:conciliar-pagos')->everyMinute()->withoutOverlapping();
