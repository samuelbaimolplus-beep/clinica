<?php

namespace App\Jobs;

use App\Models\BookingRequest;
use App\Services\Payments\OnlineBookingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcilePayPalPayment implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $bookingId) {}

    public function handle(OnlineBookingService $service): void
    {
        $booking = BookingRequest::find($this->bookingId);
        if ($booking) {
            $service->reconcile($booking, true);
        }
    }
}
