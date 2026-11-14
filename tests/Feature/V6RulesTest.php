<?php

namespace Tests\Feature;

use App\Models\AjusteAgenda;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\EventoAuditoria;
use App\Models\Horario;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class V6RulesTest extends TestCase
{
    use DatabaseTransactions;

    private Usuario $reception;

    private Usuario $admin;

    private Medico $doctor;

    private Especialidad $specialty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('clinica_vida_salud_test', config('database.connections.pgsql.database'));
        $this->travelTo(Carbon::parse('2026-11-09 07:00', 'America/La_Paz'));
        $this->reception = $this->verifiedAccount(['name' => 'Recepción V6', 'email' => 'reception-v6@example.test', 'password' => 'PruebaSegura2026', 'role' => 'Recepcionista', 'status' => 'Activo']);
        $this->admin = $this->verifiedAccount(['name' => 'Administrador V6', 'email' => 'admin-v6@example.test', 'password' => 'PruebaSegura2026', 'role' => 'Administrador', 'status' => 'Activo']);
        $this->specialty = Especialidad::create(['name' => 'Especialidad V6', 'status' => 'Activo']);
        $this->doctor = $this->createDoctor('V6-A');
        $this->actingAs($this->reception);
    }

    private function createDoctor(string $registration): Medico
    {
        $doctor = Medico::create(['registration' => $registration, 'first_name' => 'Médico', 'last_name' => $registration, 'email' => $registration.'@example.test', 'phone' => '70000000', 'status' => 'Activo']);
        $doctor->specialties()->attach($this->specialty);
        Horario::create(['doctor_id' => $doctor->id, 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '12:00', 'duration' => 30]);

        return $doctor;
    }

    private function block(string $start = '08:00', string $end = '08:30', string $date = '2026-11-09'): array
    {
        return ['doctor_id' => $this->doctor->id, 'date' => $date, 'start_time' => $start, 'end_time' => $end];
    }

    public function test_demand_cannot_be_proposed_or_created_for_a_different_day(): void
    {
        foreach (['2026-11-08', '2026-11-16'] as $date) {
            $this->postJson('/api/agenda-adjustments/proposal', ['date' => $date, 'specialty_id' => $this->specialty->id, 'quantity' => 1])->assertUnprocessable();
            $this->postJson('/api/agenda-adjustments', ['kind' => 'presential_block', 'specialty_id' => $this->specialty->id, 'blocks' => [$this->block(date: $date)]])->assertUnprocessable();
        }
        $this->assertDatabaseCount('agenda_adjustments', 0);
    }

    public function test_batch_release_cannot_be_used_to_revert_an_online_opening(): void
    {
        $block = $this->postJson('/api/agenda-adjustments', ['kind' => 'presential_block', 'specialty_id' => $this->specialty->id, 'quantity' => 5, 'blocks' => [$this->block()]])->assertCreated()->json('0.id');
        $opening = $this->postJson('/api/agenda-adjustments', ['kind' => 'online_opening', 'blocks' => [$this->block('08:30', '09:00')]])->assertCreated()->json('0.id');
        $this->postJson('/api/agenda-adjustments/release', ['ids' => [$block, $opening]])->assertUnprocessable();
        $this->assertSame('active', AjusteAgenda::findOrFail($block)->status);
        $this->patchJson('/api/agenda-adjustments/'.$opening.'/release')->assertUnprocessable();
        $this->assertSame('active', AjusteAgenda::findOrFail($opening)->status);
        $this->postJson('/api/agenda-adjustments/release', ['ids' => [$block]])->assertOk();
        $this->assertSame('released', AjusteAgenda::findOrFail($block)->status);
    }

    public function test_selected_demand_blocks_preserve_original_quantity_and_do_not_expire(): void
    {
        $id = $this->postJson('/api/agenda-adjustments', ['kind' => 'presential_block', 'specialty_id' => $this->specialty->id, 'quantity' => 12, 'blocks' => [$this->block()]])->assertCreated()->json('0.id');
        $record = AjusteAgenda::findOrFail($id);
        $this->assertSame(12, $record->demand_quantity);
        $this->assertNotNull($record->demand_batch);
        $this->travel(1)->days();
        $this->patchJson('/api/agenda-adjustments/'.$id.'/release')->assertUnprocessable();
        $this->assertSame('active', $record->fresh()->status);
    }

    public function test_legacy_future_demand_is_not_applied_to_future_availability_or_deleted(): void
    {
        $legacy = AjusteAgenda::create($this->block(date: '2026-11-16') + ['kind' => 'presential_block',
            'specialty_id' => $this->specialty->id, 'created_by' => $this->reception->id, 'status' => 'active']);
        $this->actingAs($this->admin)->getJson('/api/schedules/capacity?doctor_id='.$this->doctor->id.'&date=2026-11-16')
            ->assertOk()->assertJsonPath('blocks.0.demand_blocked', false);
        $this->assertSame('active', $legacy->fresh()->status);
    }

    public function test_schedule_remainders_are_left_unused_without_inventing_short_consultations(): void
    {
        $this->actingAs($this->admin)->postJson('/api/schedules', ['doctor_id' => $this->doctor->id, 'weekday' => 2, 'start_time' => '08:00', 'end_time' => '10:10', 'duration' => 30])->assertCreated();
        $this->getJson('/api/schedules/capacity?doctor_id='.$this->doctor->id.'&date=2026-11-10')->assertOk()
            ->assertJsonPath('configured_blocks', 4)->assertJsonPath('blocks.3.end_time', '10:00');
    }

    public function test_unauthorized_agenda_actions_are_denied_and_audited_without_body_secrets(): void
    {
        $this->actingAs($this->admin)->postJson('/api/agenda-adjustments', ['password' => 'DoNotRecordThisValue'])->assertForbidden();
        $this->assertDatabaseHas('audit_events', ['action' => 'access_denied', 'result' => 'denied', 'user_id' => $this->admin->id]);
        $this->assertStringNotContainsString('DoNotRecordThisValue', EventoAuditoria::all()->toJson());
        $this->getJson('/api/schedule-impacts')->assertForbidden();
    }

    public function test_specialty_search_prioritizes_free_doctor_load_and_respects_explicit_preference(): void
    {
        $other = $this->createDoctor('V6-B');
        $patient = Paciente::create(['identity' => 'V6-LOAD', 'first_name' => 'Ana', 'last_name' => 'Prueba', 'birth_date' => '2000-01-01', 'sex' => 'Femenino', 'phone' => '70000000', 'status' => 'Activo']);
        Cita::create($this->block() + ['patient_id' => $patient->id, 'specialty_id' => $this->specialty->id, 'created_by' => $this->reception->id, 'status' => 'Programada']);
        $query = '/api/medical-availability?specialty_id='.$this->specialty->id.'&date=2026-11-09';
        $this->getJson($query)->assertOk()->assertJsonPath('blocks.0.doctor_id', $other->id)->assertJsonMissingPath('blocks.0.patient_id');
        $this->getJson($query.'&doctor_id='.$this->doctor->id)->assertOk()->assertJsonPath('blocks.0.doctor_id', $this->doctor->id)->assertJsonPath('blocks.0.start_time', '08:30');
    }
}
