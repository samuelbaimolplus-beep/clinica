<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/** Criterios de aceptación del Word vigente: CU01–CU05, incluidos sus flujos alternativos. */
class EstablishedUseCasesTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Doctor $doctor;

    private Specialty $general;

    private Specialty $pediatrics;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('clinica_vida_salud_test', config('database.connections.pgsql.database'));
        $this->travelTo(Carbon::parse('2026-11-04 07:00:00', 'America/La_Paz'));
        config(['clinic.appointments_enabled' => false]);
        $this->admin = User::create(['name' => 'Administración', 'email' => 'admin@acceptance.test',
            'password' => 'ClaveSegura2026', 'role' => 'Administrador', 'status' => 'Activo']);
        $this->general = Specialty::create(['name' => 'Medicina General', 'status' => 'Activo']);
        $this->pediatrics = Specialty::create(['name' => 'Pediatría', 'status' => 'Activo']);
        $this->doctor = Doctor::create($this->doctorData());
        $this->doctor->specialties()->attach([$this->general->id, $this->pediatrics->id]);
        $this->patient = Patient::create(['identity' => 'ACCEPT-1', 'first_name' => 'Paciente', 'last_name' => 'Prueba',
            'phone' => '70000000', 'birth_date' => '2000-01-01', 'sex' => 'Femenino', 'status' => 'Activo']);
    }

    private function doctorData(array $overrides = []): array
    {
        return array_replace(['registration' => 'REG-ACEPTACION', 'first_name' => 'Ana', 'last_name' => 'Mendoza',
            'phone' => '70000000', 'email' => 'ana@acceptance.test', 'status' => 'Activo'], $overrides);
    }

    private function appointment(array $overrides = []): Appointment
    {
        return Appointment::create(array_replace(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
            'specialty_id' => $this->general->id, 'date' => '2026-11-05', 'start_time' => '08:00', 'end_time' => '08:30',
            'status' => 'Programada', 'created_by' => $this->admin->id], $overrides));
    }

    private function failAudit(): void
    {
        $logger = Mockery::mock(new AuditLogger);
        $logger->shouldReceive('record')->andThrow(new \RuntimeException('DETALLE_INTERNO_NO_PUBLICAR'));
        $this->app->instance(AuditLogger::class, $logger);
    }

    public function test_cu01_rejects_login_when_session_is_already_authenticated(): void
    {
        $this->actingAs($this->admin)->postJson('/api/login', ['email' => 'otra@acceptance.test', 'password' => 'OtraClave2026'])->assertConflict();
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_cu01_audit_failure_after_login_leaves_no_authenticated_session(): void
    {
        $this->failAudit();
        $this->postJson('/api/login', ['email' => $this->admin->email, 'password' => 'ClaveSegura2026'])
            ->assertStatus(503)->assertJsonMissingPath('user')->assertDontSee('DETALLE_INTERNO_NO_PUBLICAR');
        $this->assertGuest();
        $this->getJson('/api/references')->assertUnauthorized();
        $this->getJson('/api/session')->assertJsonPath('user', null);
    }

    public function test_cu02_expired_session_requests_new_authentication(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized()->assertJsonStructure(['csrf', 'message']);
        $this->getJson('/api/references')->assertUnauthorized();
    }

    public function test_cu02_failure_does_not_confirm_logout_while_session_remains_active(): void
    {
        $this->actingAs($this->admin);
        $this->failAudit();
        $this->postJson('/api/logout')->assertStatus(503)->assertJsonPath('message', 'No se pudo completar el cierre de sesión. Intente nuevamente.');
        $this->assertAuthenticatedAs($this->admin);
        $this->getJson('/api/references')->assertOk();
    }

    public function test_cu03_read_only_details_and_status_changes_preserve_staff_data(): void
    {
        $user = User::create(['name' => 'Recepción', 'email' => 'reception@acceptance.test', 'password' => 'ClaveSegura2026',
            'role' => 'Recepcionista', 'status' => 'Inactivo']);
        $oldPassword = $user->password;
        $this->actingAs($this->admin)->getJson('/api/entities/users/'.$user->id)->assertOk()->assertJsonPath('name', 'Recepción')->assertJsonMissingPath('password');
        $this->patchJson('/api/entities/users/'.$user->id.'/status', ['status' => 'Activo', 'name' => 'No cambiar'])->assertOk()->assertJsonPath('name', 'Recepción');
        $this->assertSame($oldPassword, $user->fresh()->password);
        $this->patchJson('/api/entities/users/'.$this->admin->id.'/status', ['status' => 'Inactivo'])->assertUnprocessable();
        $this->patchJson('/api/entities/users/'.$user->id.'/status', ['status' => 'Inactivo'])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Recepción', 'status' => 'Inactivo']);
    }

    public function test_cu04_search_details_and_active_initial_state(): void
    {
        $this->actingAs($this->admin)->getJson('/api/entities/doctors?search=mEnDoZa')->assertOk()->assertJsonCount(1);
        $this->getJson('/api/entities/doctors?search='.urlencode('PEDIATRÍA'))->assertOk()->assertJsonCount(1);
        $this->getJson('/api/entities/doctors?search=NoExiste')->assertOk()->assertJsonCount(0);
        $this->getJson('/api/entities/doctors/'.$this->doctor->id)->assertOk()->assertJsonCount(2, 'specialties')->assertJsonPath('registration', $this->doctor->registration);
        $this->postJson('/api/entities/doctors', $this->doctorData(['registration' => 'NEW-DOCTOR', 'email' => 'new@acceptance.test', 'status' => 'Inactivo'])
            + ['specialty_ids' => [$this->general->id]])->assertCreated()->assertJsonPath('status', 'Activo');
    }

    public function test_cu04_deactivation_identifies_only_future_active_appointments_and_preserves_history(): void
    {
        $future = $this->appointment();
        $past = $this->appointment(['date' => '2026-11-03']);
        $cancelled = $this->appointment(['date' => '2026-11-06', 'status' => 'Cancelada']);
        $this->actingAs($this->admin)->patchJson('/api/entities/doctors/'.$this->doctor->id.'/status', ['status' => 'Inactivo'])
            ->assertOk()->assertJsonCount(1, 'affected_appointments')->assertJsonPath('affected_appointments.0.id', $future->id)
            ->assertJsonPath('affected_appointments.0.reasons.0', 'Médico inactivo');
        $this->getJson('/api/entities/doctors/'.$this->doctor->id)->assertJsonCount(1, 'affected_appointments');
        $this->assertSame('Programada', $future->fresh()->status);
        $this->assertSame('Programada', $past->fresh()->status);
        $this->assertSame('Cancelada', $cancelled->fresh()->status);
        $this->assertDatabaseCount('appointments', 3);
        $this->assertSame(2, $this->doctor->specialties()->count());
    }

    public function test_cu04_removing_specialty_identifies_appointments_without_cancelling_them(): void
    {
        $affected = $this->appointment();
        $unaffected = $this->appointment(['specialty_id' => $this->pediatrics->id, 'start_time' => '09:00', 'end_time' => '09:30']);
        $this->actingAs($this->admin)->putJson('/api/entities/doctors/'.$this->doctor->id,
            $this->doctorData() + ['specialty_ids' => [$this->pediatrics->id]])->assertOk()
            ->assertJsonCount(1, 'affected_appointments')->assertJsonPath('affected_appointments.0.id', $affected->id)
            ->assertJsonPath('affected_appointments.0.reasons.0', 'Especialidad retirada del médico');
        $this->assertSame('Programada', $affected->fresh()->status);
        $this->assertSame('Programada', $unaffected->fresh()->status);
    }

    public function test_cu04_inactive_specialty_cannot_be_selected_even_if_previously_linked(): void
    {
        $this->general->update(['status' => 'Inactivo']);
        $this->actingAs($this->admin)->putJson('/api/entities/doctors/'.$this->doctor->id,
            $this->doctorData() + ['specialty_ids' => [$this->general->id, $this->pediatrics->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('specialty_ids.0');
        // Cambiar solo el estado no revalida ni elimina relaciones históricas.
        $this->patchJson('/api/entities/doctors/'.$this->doctor->id.'/status', ['status' => 'Inactivo'])->assertOk();
        $this->assertSame(2, $this->doctor->specialties()->count());
    }

    public function test_cu05_normalizes_spaces_and_case_and_enforces_it_in_postgresql(): void
    {
        $this->actingAs($this->admin)->postJson('/api/entities/specialties', ['name' => '  medicina   GENERAL  '])->assertUnprocessable();
        $this->postJson('/api/entities/specialties', ['name' => "  Cirugía\t  General  ", 'status' => 'Inactivo'])
            ->assertCreated()->assertJsonPath('name', 'Cirugía General')->assertJsonPath('status', 'Activo');
        $this->getJson('/api/entities/specialties?search=GENERAL')->assertOk()->assertJsonCount(2);
        $this->getJson('/api/entities/specialties?search=%25')->assertOk()->assertJsonCount(0);
        try {
            DB::transaction(fn () => Specialty::create(['name' => 'MEDICINA   GENERAL', 'status' => 'Activo']));
            $this->fail('PostgreSQL permitió un nombre normalizado duplicado.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->errorInfo[0]);
        }
    }

    public function test_cu05_deactivation_preserves_links_and_identifies_future_appointments(): void
    {
        $appointment = $this->appointment();
        $this->actingAs($this->admin)->patchJson('/api/entities/specialties/'.$this->general->id.'/status', ['status' => 'Inactivo'])
            ->assertOk()->assertJsonPath('affected_appointments.0.id', $appointment->id)
            ->assertJsonPath('affected_appointments.0.reasons.0', 'Especialidad inactiva');
        $this->getJson('/api/entities/specialties/'.$this->general->id)->assertOk()->assertJsonCount(1, 'affected_appointments');
        $this->assertSame('Programada', $appointment->fresh()->status);
        $this->assertTrue($this->doctor->specialties()->whereKey($this->general->id)->exists());
        $this->patchJson('/api/entities/specialties/'.$this->general->id.'/status', ['status' => 'Activo'])->assertOk()->assertJsonCount(0, 'affected_appointments');
    }

    public function test_catalog_audit_failure_rolls_back_changes_and_hides_internal_error(): void
    {
        $this->actingAs($this->admin);
        $this->failAudit();
        $this->putJson('/api/entities/specialties/'.$this->general->id, ['name' => 'No debe persistir', 'status' => 'Activo'])
            ->assertStatus(503)->assertDontSee('DETALLE_INTERNO_NO_PUBLICAR')->assertJsonMissingPath('trace');
        $this->assertSame('Medicina General', $this->general->fresh()->name);
    }

    public function test_reception_can_read_pending_catalog_impacts_without_enabling_booking_or_exposing_patient_data(): void
    {
        $appointment = $this->appointment();
        $this->general->update(['status' => 'Inactivo']);
        $reception = User::create(['name' => 'Recepción', 'email' => 'impact@acceptance.test', 'password' => 'ClaveSegura2026',
            'role' => 'Recepcionista', 'status' => 'Activo']);
        $this->actingAs($reception)->getJson('/api/catalog-impacts')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', $appointment->id)->assertJsonMissingPath('0.patient_id')->assertJsonMissingPath('0.patient');
        $this->postJson('/api/appointments', [])->assertNotFound();
        $this->assertSame('Programada', $appointment->fresh()->status);
        $physician = User::create(['name' => 'Profesional', 'email' => 'physician@acceptance.test', 'password' => 'ClaveSegura2026',
            'role' => 'Médico', 'doctor_id' => $this->doctor->id, 'status' => 'Activo']);
        $this->actingAs($physician)->getJson('/api/catalog-impacts')->assertForbidden();
    }

    public function test_detail_and_status_endpoints_reject_non_administrators(): void
    {
        $reception = User::create(['name' => 'Recepción', 'email' => 'denied@acceptance.test', 'password' => 'ClaveSegura2026',
            'role' => 'Recepcionista', 'status' => 'Activo']);
        $this->actingAs($reception);
        foreach (['users' => $this->admin->id, 'doctors' => $this->doctor->id, 'specialties' => $this->general->id] as $catalog => $id) {
            $this->getJson('/api/entities/'.$catalog.'/'.$id)->assertForbidden();
            $this->patchJson('/api/entities/'.$catalog.'/'.$id.'/status', ['status' => 'Inactivo'])->assertForbidden();
        }
    }
}
