<?php

namespace Tests\Feature;

use App\Compartido\Catalogos\Servicios\CatalogService;
use App\Models\AuditEvent;
use App\Models\Doctor;
use App\Models\Schedule;
use App\Models\Specialty;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\AuditLogger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditDetailsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('clinica_vida_salud_test', config('database.connections.pgsql.database'));
    }

    public function test_catalog_changes_include_actual_values_actor_and_safe_request_context(): void
    {
        $admin = $this->verifiedAccount(['name' => 'Ana Administradora', 'username' => 'audit.admin', 'email' => 'audit-admin@example.test', 'password' => 'ClavePrivada2026!', 'role' => 'Administrador', 'status' => 'Activo']);
        $this->actingAs($admin);
        $id = $this->postJson('/api/entities/specialties', ['name' => 'Medicina de auditoría', 'fee_amount' => '120.00', 'status' => 'Activo'])->assertCreated()->json('id');
        $created = AuditEvent::where('record_type', 'Specialty')->where('record_id', $id)->where('action', 'created')->firstOrFail();
        $this->assertNull($created->details['changes']['name']['before']);
        $this->assertSame('Medicina de auditoría', $created->details['changes']['name']['after']);
        $this->putJson('/api/entities/specialties/'.$id.'?token=DO_NOT_RECORD', ['name' => 'Medicina renombrada', 'fee_amount' => '180.00', 'status' => 'Inactivo'])
            ->assertOk();
        $event = AuditEvent::where('record_type', 'Specialty')->where('record_id', $id)->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertEquals(['before' => 'Medicina de auditoría', 'after' => 'Medicina renombrada'], $event->details['changes']['name']);
        $this->assertEquals(['before' => 12000, 'after' => 18000], $event->details['changes']['fee_minor']);
        $this->assertEquals(['before' => 'Activo', 'after' => 'Inactivo'], $event->details['changes']['status']);
        $this->assertSame('Ana Administradora', $event->details['actor']['name']);
        $this->assertSame('PUT', $event->details['request']['method']);
        $this->assertStringNotContainsString('DO_NOT_RECORD', json_encode($event->details));
        $admin->update(['name' => 'Nuevo nombre']);
        $this->getJson('/api/audit/'.$event->id)->assertOk()->assertJsonPath('details.actor.name', 'Ana Administradora')
            ->assertJsonPath('details.changes.status.before', 'Activo');
        $this->getJson('/api/audit?module=specialties')->assertOk()->assertJsonPath('data.0.details.record.name', 'Medicina renombrada');
        $this->postJson('/api/logout')->assertOk();
        $this->postJson('/api/login', ['email' => 'audit.admin', 'password' => 'RejectedSecret2026!'])->assertUnprocessable();
        $failure = AuditEvent::where('module', 'auth')->where('action', 'login')->where('result', 'failure')->latest('id')->firstOrFail();
        $this->assertSame('audit.admin', $failure->details['record']['username']);
        $this->assertNull($failure->user_id);
        $this->assertStringNotContainsString('RejectedSecret2026!', json_encode($failure->details));
    }

    public function test_specialty_assignment_records_removed_and_added_names_and_schedule_context(): void
    {
        $admin = $this->verifiedAccount(['name' => 'Admin', 'email' => 'audit-assign@example.test', 'password' => 'ClavePrivada2026!', 'role' => 'Administrador', 'status' => 'Activo']);
        $this->actingAs($admin);
        $first = Specialty::create(['name' => 'Auditoría primera', 'status' => 'Activo']);
        $second = Specialty::create(['name' => 'Auditoría segunda', 'status' => 'Activo']);
        $doctor = Doctor::create(['registration' => 'AUD-02', 'first_name' => 'Daniel', 'last_name' => 'Prueba', 'email' => 'audit-doctor@example.test', 'phone' => '70000000', 'status' => 'Activo']);
        $doctor->specialties()->attach($first);
        app(CatalogService::class)->guardar($doctor, ['specialty_ids' => [$second->id]], $admin);
        $event = AuditEvent::where('action', 'specialties_assigned')->where('record_id', $doctor->id)->latest('id')->firstOrFail();
        $this->assertSame([['id' => $first->id, 'name' => $first->name]], $event->details['changes']['specialties']['before']);
        $this->assertSame([['id' => $second->id, 'name' => $second->name]], $event->details['changes']['specialties']['after']);
        $schedule = Schedule::create(['doctor_id' => $doctor->id, 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '12:00', 'duration' => 30]);
        $event = AuditEvent::where('record_type', 'Schedule')->where('record_id', $schedule->id)->latest('id')->firstOrFail();
        $this->assertSame('Daniel Prueba', $event->details['related']['doctor_id']['label']);
        $schedule->delete();
        $deleted = AuditEvent::where('record_type', 'Schedule')->where('record_id', $schedule->id)->where('action', 'deleted')->firstOrFail();
        $this->assertSame('08:00', $deleted->details['changes']['start_time']['before']);
        $this->assertNull($deleted->details['changes']['start_time']['after']);
    }

    public function test_password_changes_are_identified_without_persisting_secret_values_and_audit_remains_restricted(): void
    {
        $admin = $this->verifiedAccount(['name' => 'Admin', 'email' => 'audit-security@example.test', 'password' => 'BeforePrivate2026!', 'role' => 'Administrador', 'status' => 'Activo']);
        $hash = $admin->password;
        $this->actingAs($admin);
        $admin->update(['password' => 'AfterPrivate2026!']);
        $event = AuditEvent::where('record_type', 'User')->where('record_id', $admin->id)->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertTrue($event->details['security_changed']);
        $json = json_encode($event->details);
        foreach (['BeforePrivate2026!', 'AfterPrivate2026!', $hash, $admin->password, 'password', 'remember_token'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $reception = $this->verifiedAccount(['name' => 'Recepción', 'email' => 'audit-reception@example.test', 'password' => 'ClavePrivada2026!', 'role' => 'Recepcionista', 'status' => 'Activo']);
        $this->actingAs($reception)->getJson('/api/audit/'.$event->id)->assertForbidden();
    }

    public function test_sql_and_application_both_strip_nested_secrets_without_rewriting_legacy_events(): void
    {
        DB::statement('CALL public.registrar_auditoria(NULL, ?, ?, NULL, NULL, ?, ?::jsonb)', ['sql', 'test', 'success', json_encode([
            'fields' => ['status'], 'changes' => ['status' => ['before' => 'Activo', 'after' => 'Inactivo'], 'password' => ['before' => 'SQL_SECRET']],
            'record' => ['name' => 'Registro', 'remember_token' => 'TOKEN_SECRET'],
            'actor' => ['name' => 'Sistema', 'secret' => 'ACTOR_SECRET'], 'not_allowed' => 'UNEXPECTED',
        ])]);
        $event = AuditEvent::where('module', 'sql')->where('action', 'test')->latest('id')->firstOrFail();
        $json = json_encode($event->details);
        foreach (['SQL_SECRET', 'TOKEN_SECRET', 'ACTOR_SECRET', 'UNEXPECTED'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertSame('Inactivo', $event->details['changes']['status']['after']);
        app(AuditLogger::class)->registrar('sql', 'legacy', detalles: ['fields' => ['status'], 'reason' => 'Evento sin valores']);
        $legacy = AuditEvent::where('action', 'legacy')->latest('id')->firstOrFail();
        $this->assertArrayNotHasKey('changes', $legacy->details);
    }
}
