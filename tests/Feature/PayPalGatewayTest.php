<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Paquetes\P07_ReservasPagosYSeguimientos\Compartido\Integraciones\PayPalGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PayPalGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['paypal.enabled' => true, 'paypal.client_id' => 'test-client', 'paypal.secret' => 'test-secret', 'paypal.bob_per_usd' => '10']);
        Http::preventStrayRequests();
    }

    private function payment(): Payment
    {
        return (new Payment)->forceFill(['id' => 71, 'booking_request_id' => 31, 'attempt' => 1, 'method' => 'paypal',
            'reference' => 'ORDER-31', 'provider_amount_minor' => 1255, 'provider_currency' => 'USD', 'exchange_rate' => '10']);
    }

    private function order(): array
    {
        return ['id' => 'ORDER-31', 'intent' => 'CAPTURE', 'status' => 'APPROVED', 'purchase_units' => [[
            'reference_id' => 'CVS-31-1', 'custom_id' => 'CVS-31-1', 'invoice_id' => 'CVS-31-1',
            'amount' => ['currency_code' => 'USD', 'value' => '12.55'],
        ]]];
    }

    public function test_conversion_rounds_using_integer_cents_and_the_snapshot_survives_rate_changes(): void
    {
        $gateway = new PayPalGateway;
        $this->assertSame(1255, $gateway->instantaneaImporte(12550)['provider_amount_minor']);
        config(['paypal.bob_per_usd' => '3']);
        $this->assertSame(33, $gateway->instantaneaImporte(100)['provider_amount_minor']);
        $this->assertSame('12.55', $gateway->importeUsd($this->payment()));
        $this->assertTrue($gateway->coincideConPago($this->order(), $this->payment()));
    }

    public function test_zero_rate_disables_payments_and_rejects_conversion(): void
    {
        config(['paypal.bob_per_usd' => '0']);
        $gateway = new PayPalGateway;
        $this->assertFalse($gateway->estaConfigurada());
        $this->expectException(ValidationException::class);
        $gateway->instantaneaImporte(10000);
    }

    public function test_amount_currency_identity_and_multiple_units_must_match(): void
    {
        $gateway = new PayPalGateway;
        foreach (['id', 'currency', 'amount', 'custom', 'invoice', 'units'] as $change) {
            $order = $this->order();
            match ($change) {
                'id' => $order['id'] = 'OTHER',
                'currency' => $order['purchase_units'][0]['amount']['currency_code'] = 'BOB',
                'amount' => $order['purchase_units'][0]['amount']['value'] = '12.54',
                'custom' => $order['purchase_units'][0]['custom_id'] = 'CVS-99-1',
                'invoice' => $order['purchase_units'][0]['invoice_id'] = 'CVS-99-1',
                'units' => $order['purchase_units'][] = $order['purchase_units'][0],
            };
            $this->assertFalse($gateway->coincideConPago($order, $this->payment()), $change);
        }
    }

    public function test_approval_alone_never_confirms_and_capture_retries_keep_the_same_request_id(): void
    {
        $order = $this->order();
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            '*/v2/checkout/orders/ORDER-31' => Http::response($order),
            '*/v2/checkout/orders/ORDER-31/capture' => Http::response($order),
        ]);
        $gateway = new PayPalGateway;
        $this->assertSame('pending', $gateway->consultarEstado($this->payment())['status']);
        $this->assertSame('pending', $gateway->consultarEstado($this->payment())['status']);
        $captures = Http::recorded(fn ($request) => str_ends_with($request->url(), '/capture'));
        $this->assertCount(2, $captures);
        foreach ($captures as [$request]) {
            $this->assertTrue($request->hasHeader('PayPal-Request-Id', 'CVS-CAPTURE-71'));
            $this->assertStringStartsWith('https://api-m.sandbox.paypal.com/', $request->url());
        }
    }

    public function test_completed_order_requires_one_final_capture_of_the_full_amount(): void
    {
        $order = [];
        Http::fake(function ($request) use (&$order) {
            return Http::response(str_ends_with($request->url(), '/v1/oauth2/token') ? ['access_token' => 'token'] : $order);
        });
        foreach (['missing', 'wrong_amount', 'partial', 'duplicate', 'valid'] as $scenario) {
            $order = $this->order();
            $order['status'] = 'COMPLETED';
            $capture = ['id' => 'CAP-71', 'status' => 'COMPLETED', 'final_capture' => $scenario !== 'partial',
                'amount' => ['currency_code' => 'USD', 'value' => $scenario === 'wrong_amount' ? '0.01' : '12.55']];
            $order['purchase_units'][0]['payments']['captures'] = match ($scenario) {
                'missing' => [], 'duplicate' => [$capture, $capture], default => [$capture],
            };
            $this->assertSame($scenario === 'valid' ? 'confirmed' : 'unknown', (new PayPalGateway)->consultarEstado($this->payment())['status'], $scenario);
        }
    }

    public function test_approval_links_require_sandbox_and_the_matching_order_token(): void
    {
        $gateway = new PayPalGateway;
        foreach (['https://www.paypal.com/checkoutnow?token=ORDER-31', 'https://evil.test/checkoutnow?token=ORDER-31',
            'https://user@www.sandbox.paypal.com/checkoutnow?token=ORDER-31', 'https://www.sandbox.paypal.com/checkoutnow?token=OTHER'] as $url) {
            $this->assertNull($gateway->urlAprobacion(['id' => 'ORDER-31', 'links' => [['rel' => 'approve', 'href' => $url]]]));
        }
    }

    public function test_checkout_returns_to_the_configured_https_url_behind_a_local_tunnel(): void
    {
        config(['app.url' => 'https://clinica-publica.example.test/']);
        Http::fake(['*/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            '*/v2/checkout/orders' => Http::response($this->order())]);
        (new PayPalGateway)->crearOrden($this->payment());
        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/v2/checkout/orders')) {
                return false;
            }
            $context = $request['payment_source']['paypal']['experience_context'];

            return $context['return_url'] === 'https://clinica-publica.example.test/pagos/paypal/retorno?operacion=31'
                && $context['cancel_url'] === 'https://clinica-publica.example.test/pagos/paypal/cancelado?operacion=31';
        });
    }
}
