<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorQuota;
use App\Models\Patient;
use App\Models\Schedule;
use App\Models\Specialty;
use App\Models\Unavailability;
use App\Models\User;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\AuditLogger;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class DoctorQuotaTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('clinica_vida_salud_test', config('database.connections.pgsql.database'));
        $this->travelTo(Carbon::parse('2026-11-04 07:00:00', 'America/La_Paz'));
        config(['clinic.appointments_enabled' => false]);
        $this->admin = $this->verifiedAccount(['name' => 'Administración', 'email' => 'quota-admin@example.test',
            'password' => 'ClaveSegura2026', 'role' => 'Administrador', 'status' => 'Activo']);
        $this->doctor = Doctor::create(['registration' => 'QUOTA-DOC', 'first_name' => 'Ana', 'last_name' => 'Prueba',
            'email' => 'quota-doc@example.test', 'phone' => '70000000', 'status' => 'Activo']);
        Schedule::create(['doctor_id' => $this->doctor->id, 'weekday' => 3, 'start_time' => '08:00', 'end_time' => '12:30', 'duration' => 30]);
    }

    private function saveQuota(string $presential, string $online)
    {
        return $this->actingAs($this->admin)->putJson('/api/doctor-quotas', ['doctor_id' => $this->doctor->id,
            'presential_percent' => $presential, 'online_percent' => $online]);
    }

    private function capacity()
    {
        return $this->getJson('/api/schedules/capacity?doctor_id='.$this->doctor->id.'&date=2026-11-04');
    }

    public function test_saved_percentages_produce_the_five_examples_through_the_api(): void
    {
        foreach ([['50', '50', 3, 2], ['60', '40', 3, 2], ['70', '30', 4, 1], ['35', '65', 2, 3], ['20', '80', 1, 4]] as [$presential, $online, $expectedPresential, $expectedOnline]) {
            $this->saveQuota($presential, $online)->assertOk();
            $this->capacity()->assertOk()->assertJsonPath('total_capacity', 9)->assertJsonPath('protected_capacity', 4)
                ->assertJsonPath('presential_capacity', $expectedPresential + 4)->assertJsonPath('online_capacity', $expectedOnline);
        }
        $this->assertDatabaseCount('doctor_quotas', 1);
        $this->assertDatabaseHas('audit_events', ['record_type' => 'DoctorQuota', 'module' => 'schedules', 'user_id' => $this->admin->id]);
    }

    public function test_configuration_is_per_doctor_and_uses_documented_default_with_whole_percentages(): void
    {
        $other = Doctor::create(['registration' => 'QUOTA-OTHER', 'first_name' => 'Otro', 'last_name' => 'Profesional',
            'email' => 'quota-other@example.test', 'phone' => '70000001', 'status' => 'Activo']);
        $this->actingAs($this->admin);
        $this->capacity()->assertOk()->assertJsonPath('uses_default_quota', true)->assertJsonPath('presential_capacity', 7);
        $this->saveQuota('35.25', '64.75')->assertUnprocessable();
        $this->saveQuota('35', '65')->assertOk()->assertJsonPath('presential_percent', '35.00');
        $this->capacity()->assertJsonPath('presential_capacity', 6)->assertJsonPath('online_capacity', 3);
        $this->getJson('/api/schedules?doctor_id='.$this->doctor->id)->assertJsonPath('quota.online_percent', '65.00');
        $this->getJson('/api/schedules?doctor_id='.$other->id)->assertJsonPath('quota.online_percent', 50);
    }

    public function test_partial_unavailability_removes_whole_block_without_reassigning_other_modalities(): void
    {
        Unavailability::create(['doctor_id' => $this->doctor->id, 'starts_at' => '2026-11-04 08:10', 'ends_at' => '2026-11-04 08:20', 'reason' => 'Ausencia']);
        $this->saveQuota('50', '50')->assertOk();
        $this->capacity()->assertOk()->assertJsonPath('configured_blocks', 9)->assertJsonPath('blocked_blocks', 1)
            ->assertJsonPath('total_capacity', 8)->assertJsonPath('presential_capacity', 6)->assertJsonPath('online_capacity', 2);
        $this->getJson('/api/schedules/capacity?doctor_id='.$this->doctor->id.'&date=2026-11-05')->assertJsonPath('total_capacity', 0)
            ->assertJsonPath('presential_capacity', 0)->assertJsonPath('online_capacity', 0);
    }

    public function test_invalid_percentages_do_not_overwrite_valid_configuration(): void
    {
        $this->saveQuota('50', '50')->assertOk();
        foreach ([['60', '60'], ['-1', '101'], ['101', '0'], ['35.001', '64.999'], ['text', '50']] as [$presential, $online]) {
            $this->saveQuota($presential, $online)->assertUnprocessable();
        }
        $this->assertSame('50.00', DoctorQuota::firstOrFail()->presential_percent);
        try {
            DB::transaction(fn () => DoctorQuota::firstOrFail()->update(['presential_percent' => 80]));
            $this->fail('PostgreSQL permitió porcentajes que no suman 100%.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
        try {
            DB::transaction(fn () => DoctorQuota::firstOrFail()->update(['presential_percent' => 35.25, 'online_percent' => 64.75]));
            $this->fail('PostgreSQL permitió nuevas configuraciones decimales.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
    }

    public function test_changing_distribution_preserves_existing_appointments_and_keeps_booking_disabled(): void
    {
        $specialty = Specialty::create(['name' => 'Especialidad de cuotas', 'status' => 'Activo']);
        $this->doctor->specialties()->attach($specialty);
        $patient = Patient::create(['identity' => 'QUOTA-PAT', 'first_name' => 'Paciente', 'last_name' => 'Prueba',
            'phone' => '70000000', 'birth_date' => '2000-01-01', 'sex' => 'Femenino', 'status' => 'Activo']);
        $appointment = Appointment::create(['patient_id' => $patient->id, 'doctor_id' => $this->doctor->id,
            'specialty_id' => $specialty->id, 'date' => '2026-11-04', 'start_time' => '08:00', 'end_time' => '08:30',
            'status' => 'Confirmada', 'created_by' => $this->admin->id]);
        $before = $appointment->fresh()->getAttributes();
        $this->saveQuota('100', '0')->assertOk();
        $this->saveQuota('0', '100')->assertOk();
        $this->assertSame($before, $appointment->fresh()->getAttributes());
        $this->postJson('/api/appointments', [])->assertNotFound();
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_only_administrators_can_configure_and_inactive_doctors_are_rejected(): void
    {
        $reception = $this->verifiedAccount(['name' => 'Recepción', 'email' => 'quota-reception@example.test', 'password' => 'ClaveSegura2026',
            'role' => 'Recepcionista', 'status' => 'Activo']);
        $this->actingAs($reception)->putJson('/api/doctor-quotas', ['doctor_id' => $this->doctor->id,
            'presential_percent' => 50, 'online_percent' => 50])->assertForbidden();
        $this->capacity()->assertForbidden();
        $this->doctor->update(['status' => 'Inactivo']);
        $this->saveQuota('50', '50')->assertUnprocessable();
        $this->assertDatabaseCount('doctor_quotas', 0);
    }

    public function test_audit_failure_rolls_back_the_quota_configuration(): void
    {
        $logger = Mockery::mock(new AuditLogger);
        $logger->shouldReceive('record')->andThrow(new \RuntimeException('Fallo simulado de auditoría'));
        $this->app->instance(AuditLogger::class, $logger);
        $this->saveQuota('50', '50')->assertStatus(503);
        $this->assertDatabaseCount('doctor_quotas', 0);
    }
}
