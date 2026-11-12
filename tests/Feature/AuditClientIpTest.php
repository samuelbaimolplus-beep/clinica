<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Comprueba el origen de la IP y su consulta sin confiar en campos enviados por un usuario. */
class AuditClientIpTest extends TestCase
{
    use DatabaseTransactions;

    public function test_real_client_ip_is_recorded_and_forwarded_headers_from_an_untrusted_peer_are_ignored(): void
    {
        $this->assertSame('clinica_vida_salud_test',config('database.connections.pgsql.database'));
        $user=$this->verifiedAccount(['name'=>'Auditoria IP','email'=>Str::random(10).'@ip.test','password'=>'ClavePrueba2026','role'=>'Administrador','status'=>'Activo']);
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.25'])->withHeader('X-Forwarded-For','198.51.100.99')
            ->postJson('/api/login',['email'=>$user->email,'password'=>'ClavePrueba2026'])->assertOk();
        $event=AuditEvent::where('user_id',$user->id)->where('action','login')->latest('id')->firstOrFail();
        $this->assertSame('203.0.113.25',$event->ip_address);
        $this->getJson('/api/audit/'.$event->id)->assertOk()->assertJsonPath('ip_address','203.0.113.25');
        $this->getJson('/api/audit?ip_address=203.0.113.25')->assertOk()->assertJsonPath('data.0.ip_address','203.0.113.25');
        $this->getJson('/api/audit?ip_address=invalid')->assertUnprocessable();
    }

    public function test_trusted_local_tunnel_preserves_ipv6_client_ip(): void
    {
        $user=$this->verifiedAccount(['name'=>'Auditoria tunel','email'=>Str::random(10).'@ip.test','password'=>'ClavePrueba2026','role'=>'Administrador','status'=>'Activo']);
        $this->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])->withHeader('X-Forwarded-For','2001:db8::27')
            ->postJson('/api/login',['email'=>$user->email,'password'=>'ClavePrueba2026'])->assertOk();
        $this->assertSame('2001:db8::27',AuditEvent::where('user_id',$user->id)->where('action','login')->latest('id')->value('ip_address'));
    }
}
