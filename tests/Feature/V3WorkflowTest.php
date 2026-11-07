<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Schedule;
use App\Models\Specialty;
use App\Models\Unavailability;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class V3WorkflowTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $reception;

    private User $patientUser;

    private Doctor $doctor;

    private Patient $patient;

    private Specialty $specialty;

    private Schedule $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('clinica_vida_salud_test', config('database.connections.pgsql.database'));
        $this->travelTo(Carbon::parse('2026-11-09 07:00:00', 'America/La_Paz'));
        config(['clinic.appointments_enabled' => false]);
        $this->admin = User::create(['name' => 'Administración V3', 'email' => 'admin-v3@example.test', 'role' => 'Administrador', 'status' => 'Activo', 'password' => 'ClavePrueba2026']);
        $this->reception = User::create(['name' => 'Recepción V3', 'email' => 'reception-v3@example.test', 'role' => 'Recepcionista', 'status' => 'Activo', 'password' => 'ClavePrueba2026']);
        $this->doctor = Doctor::create(['registration' => 'V3-DOC', 'first_name' => 'Ana', 'last_name' => 'Médica', 'email' => 'doc-v3@example.test', 'phone' => '70000001', 'status' => 'Activo']);
        $this->specialty = Specialty::create(['name' => 'Especialidad V3', 'status' => 'Activo']);
        $this->doctor->specialties()->attach($this->specialty);
        $this->patient = Patient::create(['identity' => null, 'first_name' => 'Paciente', 'last_name' => 'V3', 'birth_date' => '2000-01-01', 'sex' => 'Femenino', 'phone' => '70000002', 'address' => 'Dirección privada', 'status' => 'Activo']);
        $this->patientUser = User::create(['name' => 'Paciente V3', 'email' => 'patient-v3@example.test', 'role' => 'Paciente', 'patient_id' => $this->patient->id, 'status' => 'Activo', 'password' => 'ClavePrueba2026']);
        $this->patientUser->forceFill(['email_verified_at' => now()])->save();
        $this->shift = Schedule::create($this->shiftData());
    }

    private function shiftData(array $overrides = []): array
    {
        return array_replace(['doctor_id' => $this->doctor->id, 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '12:30', 'duration' => 30], $overrides);
    }

    private function block(string $start = '08:00', string $end = '08:30'): array
    {
        return ['doctor_id' => $this->doctor->id, 'date' => '2026-11-09', 'start_time' => $start, 'end_time' => $end];
    }

    private function appointment(string $start = '08:00', string $end = '08:30'): Appointment
    {
        return Appointment::create($this->block($start, $end) + ['patient_id' => $this->patient->id, 'specialty_id' => $this->specialty->id, 'status' => 'Programada', 'created_by' => $this->reception->id]);
    }

    private function availability(string $modality)
    {
        return $this->getJson('/api/medical-availability?'.http_build_query(['doctor_id' => $this->doctor->id, 'specialty_id' => $this->specialty->id, 'date' => '2026-11-09', 'modality' => $modality]));
    }

    public function test_patient_detail_and_status_preserve_undocumented_identity_and_history(): void
    {
        $appointment = $this->appointment();
        $before = $this->patient->fresh()->getAttributes();
        $this->actingAs($this->reception)->getJson('/api/entities/patients/'.$this->patient->id)->assertOk()->assertJsonPath('address', 'Dirección privada');
        $this->patchJson('/api/entities/patients/'.$this->patient->id.'/status', ['status' => 'Inactivo', 'first_name' => 'No cambiar'])->assertOk();
        $after = $this->patient->fresh()->getAttributes();
        unset($before['status'], $before['updated_at'], $after['status'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->assertSame('Programada', $appointment->fresh()->status);
        $this->patchJson('/api/entities/patients/'.$this->patient->id.'/status', ['status' => 'Activo'])->assertOk();
        $this->actingAs($this->admin)->getJson('/api/entities/patients/'.$this->patient->id)->assertForbidden();
        $this->actingAs($this->patientUser)->patchJson('/api/entities/patients/'.$this->patient->id.'/status', ['status' => 'Inactivo'])->assertForbidden();
    }

    public function test_new_patient_is_active_even_when_client_sends_inactive(): void
    {
        $data = $this->patient->only(['first_name', 'last_name', 'phone', 'birth_date', 'sex']);
        $this->actingAs($this->reception)->postJson('/api/entities/patients', $data + ['identity' => 'NEW-V3', 'status' => 'Inactivo'])->assertCreated()->assertJsonPath('status', 'Activo');
    }

    public function test_multiple_shifts_protect_each_start_and_alternate_only_the_remainder(): void
    {
        $this->actingAs($this->admin)->postJson('/api/schedules', $this->shiftData(['start_time' => '14:00', 'end_time' => '18:30']))->assertCreated();
        $data = $this->getJson('/api/schedules/capacity?doctor_id='.$this->doctor->id.'&date=2026-11-09')->assertOk()
            ->assertJsonPath('total_capacity', 18)->assertJsonPath('protected_capacity', 8)
            ->assertJsonPath('presential_capacity', 14)->assertJsonPath('online_capacity', 4)->json('blocks');
        $this->assertSame(['presential', 'online', 'presential', 'online', 'presential'], array_column(array_slice($data, 4, 5), 'modality'));
        $this->assertTrue($data[9]['protected']);
        $this->assertSame('14:00', $data[9]['start_time']);
        $this->postJson('/api/schedules', $this->shiftData(['start_time' => '12:30', 'end_time' => '13:30']))->assertCreated();
        $this->postJson('/api/schedules', $this->shiftData(['start_time' => '12:00', 'end_time' => '13:00']))->assertUnprocessable();
        $this->postJson('/api/schedules', $this->shiftData(['weekday' => 2, 'end_time' => '10:10']))->assertCreated();
    }

    public function test_database_rejects_overlapping_shifts_even_without_service(): void
    {
        try {
            DB::transaction(fn () => Schedule::create($this->shiftData(['start_time' => '09:00', 'end_time' => '13:00'])));
            $this->fail('PostgreSQL permitió turnos superpuestos.');
        } catch (QueryException $exception) {
            $this->assertSame('23P01', $exception->errorInfo[0]);
        }
    }

    public function test_short_shifts_and_blocks_crossing_two_hour_boundary_remain_protected(): void
    {
        $this->actingAs($this->admin)->postJson('/api/schedules', $this->shiftData(['weekday' => 2, 'end_time' => '14:00', 'duration' => 90]))->assertCreated();
        $this->getJson('/api/schedules/capacity?doctor_id='.$this->doctor->id.'&date=2026-11-10')->assertOk()
            ->assertJsonPath('protected_capacity', 2)->assertJsonPath('blocks.1.start_time', '09:30')
            ->assertJsonPath('blocks.1.protected', true)->assertJsonPath('presential_capacity', 3)->assertJsonPath('online_capacity', 1);
        $this->postJson('/api/schedules', $this->shiftData(['weekday' => 3, 'end_time' => '09:30', 'duration' => 90]))->assertCreated();
        $this->getJson('/api/schedules/capacity?doctor_id='.$this->doctor->id.'&date=2026-11-11')->assertJsonPath('protected_capacity', 1)
            ->assertJsonPath('total_capacity', 1)->assertJsonPath('online_capacity', 0);
    }

    public function test_schedule_change_identifies_affected_appointments_and_can_resolve_them_without_mutation(): void
    {
        $appointment = $this->appointment();
        $before = $appointment->fresh()->getAttributes();
        $this->actingAs($this->admin)->putJson('/api/schedules/'.$this->shift->id, $this->shiftData(['start_time' => '09:00']))->assertOk()->assertJsonCount(1, 'affected_appointments');
        $this->getJson('/api/schedule-impacts')->assertForbidden();
        $this->actingAs($this->reception)->getJson('/api/schedule-impacts')->assertOk()->assertJsonPath('0.id', $appointment->id)
            ->assertJsonPath('0.patient.id', $this->patient->id)->assertJsonPath('0.patient.phone', $this->patient->phone)->assertJsonMissingPath('0.patient.address');
        $this->assertSame($before, $appointment->fresh()->getAttributes());
        $this->actingAs($this->admin)->putJson('/api/schedules/'.$this->shift->id, $this->shiftData())->assertOk()->assertJsonCount(0, 'affected_appointments');
        $this->actingAs($this->reception)->getJson('/api/schedule-impacts')->assertJsonCount(0);
        $this->actingAs($this->patientUser)->getJson('/api/schedule-impacts')->assertForbidden();
    }

    public function test_availability_filters_modality_busy_absence_and_private_data_without_booking(): void
    {
        $this->appointment('10:30', '11:00');
        Unavailability::create(['doctor_id' => $this->doctor->id, 'starts_at' => '2026-11-09 11:40', 'ends_at' => '2026-11-09 11:50', 'reason' => 'Motivo privado']);
        $this->actingAs($this->patientUser);
        $this->availability('presential')->assertUnprocessable();
        $this->availability('online')->assertOk()->assertJsonCount(0, 'blocks');
        $response = $this->getJson('/api/medical-availability/options')->assertOk();
        $this->assertStringNotContainsString('70000001', $response->getContent());
        $this->assertStringNotContainsString('doc-v3@example.test', $response->getContent());
        $this->actingAs($this->reception);
        $this->availability('presential')->assertOk()->assertJsonCount(7, 'blocks')->assertJsonMissingPath('blocks.0.patient_id');
        $this->assertDatabaseCount('appointments', 1);
        $this->postJson('/api/appointments', [])->assertNotFound();
        $this->doctor->update(['status' => 'Inactivo']);
        $this->availability('presential')->assertJsonCount(0, 'blocks');
    }

    public function test_reception_demand_blocks_persist_without_a_warning_timer_until_manual_release(): void
    {
        $this->appointment();
        $this->actingAs($this->reception);
        $proposal = $this->postJson('/api/agenda-adjustments/proposal', ['specialty_id' => $this->specialty->id, 'date' => '2026-11-09', 'quantity' => 20])
            ->assertOk()->assertJsonPath('unfulfilled', 14)->assertJsonPath('blocks.0.start_time', '08:30')->json('blocks');
        $created = $this->postJson('/api/agenda-adjustments', ['kind' => 'presential_block', 'specialty_id' => $this->specialty->id, 'blocks' => [$proposal[0]]])->assertCreated();
        $id = $created->json('0.id');
        $this->availability('presential')->assertJsonCount(5, 'blocks');
        $this->travel(31)->minutes();
        $this->getJson('/api/agenda-adjustments?date=2026-11-09')->assertOk()->assertJsonMissingPath('0.prolonged')->assertJsonPath('0.status', 'active');
        $this->patchJson('/api/agenda-adjustments/'.$id.'/release')->assertOk()->assertJsonPath('released_by', $this->reception->id);
        $this->availability('presential')->assertJsonCount(6, 'blocks');
        $this->patchJson('/api/agenda-adjustments/'.$id.'/release')->assertUnprocessable();
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('agenda_adjustments', 1);
    }

    public function test_stale_proposal_and_duplicate_batch_roll_back_all_adjustments(): void
    {
        $this->actingAs($this->reception);
        $this->appointment('08:30', '09:00');
        $this->postJson('/api/agenda-adjustments', ['kind' => 'presential_block', 'specialty_id' => $this->specialty->id,
            'blocks' => [$this->block(), $this->block('08:30', '09:00')]])->assertUnprocessable();
        $this->assertDatabaseCount('agenda_adjustments', 0);
        $this->postJson('/api/agenda-adjustments', ['kind' => 'presential_block', 'specialty_id' => $this->specialty->id,
            'blocks' => [$this->block(), $this->block()]])->assertUnprocessable();
        $this->assertDatabaseCount('agenda_adjustments', 0);
        $this->postJson('/api/agenda-adjustments', ['kind' => 'presential_block', 'specialty_id' => $this->specialty->id,
            'blocks' => [$this->block()]])->assertCreated();
        try {
            DB::transaction(fn () => DB::table('agenda_adjustments')->insert($this->block() + ['kind' => 'presential_block',
                'specialty_id' => $this->specialty->id, 'created_by' => $this->reception->id, 'status' => 'active']));
            $this->fail('PostgreSQL permitió movimientos activos superpuestos.');
        } catch (QueryException $exception) {
            $this->assertSame('23P01', $exception->errorInfo[0]);
        }
    }

    public function test_only_reception_opens_free_protected_blocks_and_keeps_opening_on_quota_changes(): void
    {
        $data = ['kind' => 'online_opening', 'blocks' => [$this->block()]];
        $this->actingAs($this->admin)->postJson('/api/agenda-adjustments', $data)->assertForbidden();
        $this->actingAs($this->patientUser)->postJson('/api/agenda-adjustments', $data)->assertForbidden();
        $this->actingAs($this->reception)->postJson('/api/agenda-adjustments', ['kind' => 'online_opening', 'blocks' => [$this->block('10:00', '10:30')]])->assertUnprocessable();
        $id = $this->postJson('/api/agenda-adjustments', $data)->assertCreated()->json('0.id');
        $this->actingAs($this->patientUser);
        $this->availability('online')->assertJsonCount(3, 'blocks')->assertJsonPath('blocks.0.early_online', true);
        $this->actingAs($this->admin)->putJson('/api/doctor-quotas', ['doctor_id' => $this->doctor->id, 'presential_percent' => 100, 'online_percent' => 0])->assertOk();
        $this->actingAs($this->patientUser);
        $this->availability('online')->assertJsonCount(1, 'blocks')->assertJsonPath('blocks.0.start_time', '08:00');
        $this->actingAs($this->reception)->patchJson('/api/agenda-adjustments/'.$id.'/release')->assertUnprocessable();
        $this->actingAs($this->patientUser);
        $this->availability('online')->assertJsonCount(1, 'blocks');
    }

    public function test_active_movement_prevents_incompatible_shift_change_and_audit_failure_rolls_back(): void
    {
        $this->actingAs($this->reception)->postJson('/api/agenda-adjustments', ['kind' => 'presential_block', 'specialty_id' => $this->specialty->id, 'blocks' => [$this->block()]])->assertCreated();
        $this->actingAs($this->admin)->putJson('/api/schedules/'.$this->shift->id, $this->shiftData(['start_time' => '09:00']))->assertUnprocessable();
        $this->assertSame('08:00:00', $this->shift->fresh()->start_time);
        $logger = Mockery::mock(new AuditLogger);
        $logger->shouldReceive('record')->andThrow(new \RuntimeException('Fallo simulado'));
        $this->app->instance(AuditLogger::class, $logger);
        $this->actingAs($this->reception)->postJson('/api/agenda-adjustments', ['kind' => 'online_opening', 'blocks' => [$this->block('08:30', '09:00')]])->assertStatus(503);
        $this->assertDatabaseCount('agenda_adjustments', 1);
    }

    public function test_audit_filters_pagination_detail_and_permissions_do_not_expose_unknown_details(): void
    {
        $event = AuditEvent::create(['occurred_at' => '2026-11-09 23:59:59-04:00', 'user_id' => $this->admin->id,
            'module' => 'v3', 'action' => 'test_event', 'result' => 'success', 'details' => ['fields' => ['status'], 'secret' => 'DoNotExpose']]);
        $query = http_build_query(['from' => '2026-11-09', 'to' => '2026-11-09', 'module' => 'v3', 'action' => 'test_event', 'user_id' => $this->admin->id, 'result' => 'success']);
        $this->actingAs($this->admin)->getJson('/api/audit?'.$query)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $event->id)->assertJsonMissingPath('data.0.details');
        $this->getJson('/api/audit/'.$event->id)->assertOk()->assertJsonMissingPath('details.secret')->assertJsonPath('details.fields.0', 'status');
        $this->getJson('/api/audit?to=2026-11-08&module=v3')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/audit?from=2026-11-10&to=2026-11-09')->assertUnprocessable();
        $this->getJson('/api/audit/filters')->assertOk()->assertJsonStructure(['users', 'actions', 'modules', 'results']);
        AuditEvent::insert(array_fill(0, 51, ['occurred_at' => now(), 'user_id' => $this->admin->id,
            'module' => 'v3', 'action' => 'test_event', 'result' => 'success', 'details' => '{}']));
        $this->getJson('/api/audit?module=v3&page=1')->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('total', 52);
        $this->getJson('/api/audit?module=v3&page=2')->assertOk()->assertJsonCount(2, 'data');
        foreach ([$this->reception, $this->patientUser] as $user) {
            $this->actingAs($user)->getJson('/api/audit')->assertForbidden();
            $this->getJson('/api/audit/'.$event->id)->assertForbidden();
            $this->getJson('/api/audit/filters')->assertForbidden();
        }
        $this->actingAs($this->admin)->putJson('/api/audit/'.$event->id, [])->assertStatus(405);
        $this->assertDatabaseHas('audit_events', ['id' => $event->id, 'action' => 'test_event']);
    }
}
