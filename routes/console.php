<?php

use App\Models\BookingRequest;
use App\Services\Payments\OnlineBookingService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('clinica:conciliar-pagos', function () {
    $count = 0;
    BookingRequest::whereIn('status', ['held', 'processing'])->where('modality', 'online')->orderBy('id')->chunkById(50, function ($bookings) use (&$count) {
        foreach ($bookings as $booking) {
            app(OnlineBookingService::class)->reconcile($booking);
            $count++;
        }
    });
    $this->info('Operaciones revisadas: '.$count.'. Las transacciones no resueltas conservan su retención.');
})->purpose('Conciliar pagos sandbox y formalizar exclusivamente cobros confirmados');

Schedule::command('clinica:conciliar-pagos')->everyMinute()->withoutOverlapping();
