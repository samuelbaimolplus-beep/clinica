<?php

namespace App\Http\Controllers;

use App\Jobs\ReconcileWizPayPayment;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WizPayWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        $key = config('wizpay.webhook_key');
        abort_unless(is_string($key) && $key !== '' && hash_equals($key, (string) $request->bearerToken()), 401);
        $data = $request->validate(['environment' => 'required|in:SANDBOX', 'event' => 'required|string|max:100',
            'reference' => 'required|string|max:255', 'order_id' => 'nullable|string|max:100', 'qr_id' => 'nullable|string|max:100',
            'amount' => 'required|numeric|min:0', 'currency' => 'required|in:BOB']);
        if (! in_array($data['event'], ['payment.qr.success', 'payment.qr.failed', 'transaction.success', 'transaction.failed'])) {
            return ['received' => true];
        }
        DB::transaction(function () use ($data) {
            $payment = Payment::where('method', 'wizpay')->where('reference', $data['reference'])->lockForUpdate()->first();
            // Recover a generation timeout only from an authenticated provider mapping, then verify server-to-server.
            if (! $payment && preg_match('/^CVS-(\d+)-(\d+)$/', $data['order_id'] ?? '', $m)) {
                $payment = Payment::where('method', 'wizpay')->where('booking_request_id', $m[1])->where('attempt', $m[2])->whereNull('reference')->lockForUpdate()->first();
            }
            abort_unless($payment && (int) round(((float) $data['amount']) * 100) === $payment->amount_minor, 422);
            if (isset($data['qr_id']) && $payment->qr_number) {
                abort_unless($payment->qr_number === $data['qr_id'], 422);
            }
            if (! $payment->reference) {
                $payment->update(['reference' => $data['reference'], 'qr_number' => $data['qr_id'] ?? null]);
            }
            ReconcileWizPayPayment::dispatch($payment->booking_request_id)->afterCommit();
        });

        return ['received' => true];
    }
}
