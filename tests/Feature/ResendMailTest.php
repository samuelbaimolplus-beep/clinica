<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\ActivacionPorCorreo;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Resend\Client;
use Resend\Transporters\HttpTransporter;
use Resend\ValueObjects\ApiKey;
use Resend\ValueObjects\Transporter\BaseUri;
use Resend\ValueObjects\Transporter\Headers;
use Tests\TestCase;

/** SDK y notificaciones reales, con la conexión HTTPS sustituida por respuestas aisladas. */
class ResendMailTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('clinica_vida_salud_test', config('database.connections.pgsql.database'));
        config(['mail.default' => 'resend', 'services.resend.key' => 're_isolated_test_key',
            'mail.from.address' => 'onboarding@resend.dev', 'mail.from.name' => 'Clínica Vida Salud']);
        Notification::swap(new ChannelManager($this->app));
        URL::forceRootUrl('https://clinica-y8yy.onrender.com');
        URL::forceScheme('https');
    }

    private function mockApi(array $responses, array &$history): void
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::history($history));
        $client = new Client(new HttpTransporter(new HttpClient(['handler' => $handler]),
            BaseUri::from('api.resend.com'), Headers::withAuthorization(ApiKey::from('re_isolated_test_key'))));
        app('mail.manager')->extend('resend', fn () => new ResendTransport($client));
    }

    private function account(): Usuario
    {
        return Usuario::create(['name' => 'Prueba de correo', 'username' => 'resend.prueba', 'email' => 'owner@example.test',
            'password' => 'ClavePrueba2026', 'role' => 'Recepcionista', 'status' => 'Activo', 'birth_date' => '1990-01-01']);
    }

    public function test_native_laravel_resend_driver_can_be_created_from_the_installed_sdk(): void
    {
        $this->assertInstanceOf(ResendTransport::class, Mail::mailer('resend')->getSymfonyTransport());
    }

    public function test_activation_and_recovery_render_and_send_through_the_sdk_using_https(): void
    {
        $history = [];
        $this->mockApi([new Response(200, ['Content-Type' => 'application/json'], '{"id":"activation-test"}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"id":"recovery-test"}')], $history);
        $user = $this->account();
        $this->assertTrue(app(ActivacionPorCorreo::class)->enviar($user));
        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('https://api.resend.com/emails', (string) $request->getUri());
        $this->assertSame('POST', $request->getMethod());
        $message = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['owner@example.test'], $message['to']);
        $this->assertStringContainsString('onboarding@resend.dev', $message['from']);
        $this->assertStringContainsString('Verifique su correo', $message['subject']);
        $this->assertStringContainsString('https://clinica-y8yy.onrender.com', $message['html']);
        $this->assertSame(1, preg_match('/>\s*(\d{8})\s*</', $message['html'], $match));
        app(ActivacionPorCorreo::class)->confirmar($user->email, $match[1]);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame(Password::RESET_LINK_SENT, Password::sendResetLink(['email' => $user->email]));
        $this->assertCount(2, $history);
        $recovery = json_decode((string) $history[1]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['owner@example.test'], $recovery['to']);
        $this->assertStringContainsString('https://clinica-y8yy.onrender.com/password/reset/', $recovery['html']);
    }

    public function test_rejected_sender_or_recipient_is_not_reported_as_a_successful_activation_send(): void
    {
        $history = [];
        $this->mockApi([new Response(403, ['Content-Type' => 'application/json'], '{"statusCode":403,"name":"validation_error","message":"Testing recipient is not permitted"}')], $history);
        $user = $this->account();
        $this->assertFalse(app(ActivacionPorCorreo::class)->enviar($user));
        $this->assertCount(1, $history);
        $this->assertNull($user->fresh()->email_verified_at);
    }
}
