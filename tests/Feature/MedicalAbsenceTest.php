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
use App\Paquetes\P05_CitasMedicas\Compartido\Servicios\AppointmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MedicalAbsenceTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $superadmin;

    private User $reception;

    private Doctor $doctor;

    private Patient $patient;

    private Specialty $specialty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('clinica_vida_salud_test', config('database.connections.pgsql.database'));
        $this->travelTo(Carbon::parse('2026-11-12 07:00', 'America/La_Paz'));
        config(['clinic.appointments_enabled' => true]);
        foreach (['admin' => 'Administrador', 'superadmin' => 'Superadmin', 'reception' => 'Recepcionista'] as $property => $role) {
            $this->$property = $this->verifiedAccount(['name' => $role, 'email' => $property.'@absence.test', 'password' => 'PruebaAusencia2026', 'role' => $role, 'status' => 'Activo']);
        }
        $this->doctor = Doctor::create(['registration' => 'ABS-01', 'first_name' => 'Ana', 'last_name' => 'Prueba', 'email' => 'doctor@absence.test', 'phone' => '70000000', 'status' => 'Activo']);
        $this->specialty = Specialty::create(['name' => 'Especialidad ausencias', 'status' => 'Activo', 'fee_minor' => 10000]);
        $this->doctor->specialties()->attach($this->specialty);
        Schedule::create(['doctor_id' => $this->doctor->id, 'weekday' => 4, 'start_time' => '08:00', 'end_time' => '12:00', 'duration' => 30]);
        $this->patient = Patient::create(['first_name' => 'Paciente', 'last_name' => 'Prueba', 'birth_date' => '2000-01-01', 'sex' => 'Femenino', 'phone' => '70000001', 'status' => 'Activo']);
    }

    private function absence(array $overrides = []): array
    {
        return $overrides + ['doctor_id' => $this->doctor->id, 'starts_at' => '2026-11-12T08:00', 'ends_at' => '2026-11-12T10:00', 'reason' => 'Permiso'];
    }

    private function appointment(string $start = '08:00', string $end = '08:30', array $overrides = []): Appointment
    {
        return Appointment::create($overrides + ['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'specialty_id' => $this->specialty->id,
            'date' => '2026-11-12', 'start_time' => $start, 'end_time' => $end, 'status' => 'Programada', 'created_by' => $this->reception->id, 'modality' => 'presential', 'complimentary' => true]);
    }

    public function test_both_administrative_roles_can_register_review_and_withdraw_without_losing_history_or_bookings(): void
    {
        $appointment = $this->appointment();
        $before = $appointment->fresh()->getAttributes();
        foreach ([$this->admin, $this->superadmin] as $actor) {
            $this->actingAs($actor)->postJson('/api/unavailabilities/preview', $this->absence())->assertOk()->assertJsonPath('affected.0.id', $appointment->id);
            $id = $this->postJson('/api/unavailabilities', $this->absence())->assertCreated()->assertJsonCount(1, 'affected')->json('unavailability.id');
            $this->getJson('/api/unavailabilities/'.$id.'/impacts')->assertOk()->assertJsonPath('affected.0.id', $appointment->id);
            $this->getJson('/api/schedules/capacity?doctor_id='.$this->doctor->id.'&date=2026-11-12')->assertOk()->assertJsonPath('total_capacity', 4);
            $this->postJson('/api/unavailabilities/'.$id.'/cancel', ['reason' => 'Confirmó que sí atenderá'])->assertOk()
                ->assertJsonPath('unavailability.cancelled_by', $actor->id)->assertJsonPath('unavailability.cancellation_reason', 'Confirmó que sí atenderá')->assertJsonCount(0, 'affected');
            $this->getJson('/api/schedules/capacity?doctor_id='.$this->doctor->id.'&date=2026-11-12')->assertOk()->assertJsonPath('total_capacity', 8);
            $this->getJson('/api/unavailabilities/'.$id.'/impacts')->assertOk()->assertJsonCount(0, 'affected');
            $this->assertNotNull(Unavailability::findOrFail($id)->cancelled_at);
            $event = AuditEvent::where('record_type', 'Unavailability')->where('record_id', $id)->where('action', 'updated')->latest('id')->firstOrFail();
            $this->assertSame($actor->id, $event->user_id);
            $this->assertSame('Confirmó que sí atenderá', $event->details['changes']['cancellation_reason']['after']);
        }
        $this->assertSame($before, $appointment->fresh()->getAttributes());
        $slots = collect(app(AppointmentService::class)->franjasDisponibles($this->doctor->id, '2026-11-12'));
        $this->assertSame('Ocupado', $slots->firstWhere('start', '08:00')['status']);
        $this->assertSame('Disponible', $slots->firstWhere('start', '08:30')['status']);
        $this->actingAs($this->reception)->getJson('/api/schedule-impacts')->assertOk()->assertJsonCount(0);
        $this->getJson('/api/appointments?mode=affected')->assertOk()->assertJsonCount(0);
    }

    public function test_passed_start_times_do_not_hide_pending_appointments_and_boundaries_and_final_states_are_respected(): void
    {
        $first = $this->appointment();
        $second = $this->appointment('08:30', '09:00', ['status' => 'Confirmada']);
        $this->appointment('09:00', '09:30', ['status' => 'Atendida']);
        $this->appointment('09:30', '10:00', ['status' => 'Cancelada']);
        $this->appointment('09:15', '09:45', ['status' => 'Inasistencia']);
        $this->appointment('07:30', '08:00');
        $this->appointment('10:00', '10:30');
        $this->travelTo(Carbon::parse('2026-11-12 17:00', 'America/La_Paz'));
        $this->actingAs($this->admin)->postJson('/api/unavailabilities/preview', $this->absence())->assertOk()->assertJsonCount(2, 'affected')
            ->assertJsonPath('affected.0.id', $first->id)->assertJsonPath('affected.1.id', $second->id);
        $id = $this->postJson('/api/unavailabilities', $this->absence())->assertCreated()->assertJsonCount(2, 'affected')->json('unavailability.id');
        $this->actingAs($this->reception)->getJson('/api/schedule-impacts')->assertOk()->assertJsonFragment(['entity' => 'Unavailability', 'id' => $id]);
    }

    public function test_historical_pending_appointments_are_evaluated_for_the_explicit_absence_period(): void
    {
        $appointment = $this->appointment('08:00', '08:30', ['date' => '2026-11-11']);
        $id = $this->actingAs($this->admin)->postJson('/api/unavailabilities', $this->absence(['starts_at' => '2026-11-11T08:00', 'ends_at' => '2026-11-11T09:00']))
            ->assertCreated()->assertJsonPath('affected.0.id', $appointment->id)->json('unavailability.id');
        $this->actingAs($this->reception)->getJson('/api/schedule-impacts')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $appointment->id);
        $this->actingAs($this->admin)->postJson('/api/unavailabilities/'.$id.'/cancel', ['reason' => 'Corrección del permiso'])->assertOk()->assertJsonCount(0, 'affected');
    }

    public function test_withdrawing_one_absence_keeps_other_absences_and_occupied_slots_protected(): void
    {
        $appointment = $this->appointment();
        $this->actingAs($this->admin);
        $first = $this->postJson('/api/unavailabilities', $this->absence())->assertCreated()->json('unavailability.id');
        $second = $this->postJson('/api/unavailabilities', $this->absence(['ends_at' => '2026-11-12T09:00']))->assertCreated()->json('unavailability.id');
        $this->postJson('/api/unavailabilities/'.$first.'/cancel', ['reason' => 'Se mantiene el segundo permiso'])->assertOk()->assertJsonCount(1, 'affected');
        $this->getJson('/api/schedules/capacity?doctor_id='.$this->doctor->id.'&date=2026-11-12')->assertOk()->assertJsonPath('total_capacity', 6);
        $this->actingAs($this->reception)->getJson('/api/appointments?mode=affected')->assertOk()->assertJsonPath('0.id', $appointment->id)->assertJsonCount(1, '0.blocks')->assertJsonPath('0.blocks.0.id', $second);
    }

    public function test_only_administrative_roles_can_withdraw_and_reason_and_single_transition_are_required(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/unavailabilities', $this->absence())->assertCreated()->json('unavailability.id');
        $this->actingAs($this->reception)->postJson('/api/unavailabilities', $this->absence())->assertForbidden();
        $this->getJson('/api/unavailabilities/'.$id.'/impacts')->assertForbidden();
        $this->postJson('/api/unavailabilities/'.$id.'/cancel', ['reason' => 'No autorizado'])->assertForbidden();
        $this->actingAs($this->admin)->postJson('/api/unavailabilities/'.$id.'/cancel', [])->assertUnprocessable();
        $this->postJson('/api/unavailabilities/'.$id.'/cancel', ['reason' => 'El médico atenderá'])->assertOk();
        $this->postJson('/api/unavailabilities/'.$id.'/cancel', ['reason' => 'Otro motivo'])->assertStatus(409);
        $this->assertSame('El médico atenderá', Unavailability::findOrFail($id)->cancellation_reason);
        $this->postJson('/api/unavailabilities/999999999/cancel', ['reason' => 'No existe'])->assertNotFound();
    }
}
