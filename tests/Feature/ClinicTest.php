<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Schedule;
use App\Models\Specialty;
use App\Models\Unavailability;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClinicTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $reception;

    private User $physician;

    private Doctor $doctor;

    private Doctor $otherDoctor;

    private Specialty $specialty;

    private Patient $patient;

    private Patient $otherPatient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('clinica_vida_salud_test', config('database.connections.pgsql.database'), 'Estas pruebas requieren la base de datos aislada.');
        $this->travelTo(Carbon::parse('2026-10-26 07:00:00', 'America/La_Paz'));
        $this->specialty = Specialty::create(['name' => 'Medicina General', 'status' => 'Activo']);
        foreach (['doctor', 'otherDoctor'] as $i => $property) {
            $this->$property = Doctor::create(['registration' => 'RP-'.$i, 'first_name' => 'Médico', 'last_name' => 'Prueba '.$i, 'phone' => '7000000'.$i, 'email' => 'doctor'.$i.'@test.local', 'status' => 'Activo']);
            $this->$property->specialties()->attach($this->specialty);
            Schedule::create(['doctor_id' => $this->$property->id, 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '12:00', 'duration' => 30]);
        }
        foreach (['patient', 'otherPatient'] as $i => $property) {
            $this->$property = Patient::create(['identity' => 'TEST-'.$i, 'first_name' => 'Paciente', 'last_name' => 'Prueba '.$i, 'birth_date' => '2000-01-01', 'sex' => 'Femenino', 'phone' => '71111111', 'status' => 'Activo']);
        }
        $this->admin = User::create(['name' => 'Administración', 'email' => 'admin@test.local', 'password' => 'ClavePrueba2026', 'role' => 'Administrador', 'status' => 'Activo']);
        $this->reception = User::create(['name' => 'Recepción', 'email' => 'recepcion@test.local', 'password' => 'ClavePrueba2026', 'role' => 'Recepcionista', 'status' => 'Activo']);
        $this->physician = User::create(['name' => 'Profesional', 'email' => 'medico@test.local', 'password' => 'ClavePrueba2026', 'role' => 'Médico', 'status' => 'Activo', 'doctor_id' => $this->doctor->id]);
    }

    private function booking(array $overrides = []): array
    {
        return array_replace(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'specialty_id' => $this->specialty->id, 'date' => '2026-10-26', 'start_time' => '08:00', 'reason' => 'Control general'], $overrides);
    }

    private function book(array $overrides = []): Appointment
    {
        $response = $this->actingAs($this->reception)->postJson('/api/appointments', $this->booking($overrides))->assertCreated();

        return Appointment::findOrFail($response->json('id'));
    }

    public function test_authentication_rejects_empty_invalid_and_inactive_accounts_and_logs_out(): void
    {
        $this->getJson('/api/appointments?mode=agenda')->assertUnauthorized();
        $this->postJson('/api/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->postJson('/api/login', ['email' => $this->admin->email, 'password' => 'incorrecta'])->assertUnprocessable();
        $this->admin->update(['status' => 'Inactivo']);
        $this->postJson('/api/login', ['email' => $this->admin->email, 'password' => 'ClavePrueba2026'])->assertUnprocessable()->assertJsonPath('errors.email.0', 'FA03: La cuenta está inactiva. Contacte al administrador.');
        $this->admin->update(['status' => 'Activo']);
        $this->postJson('/api/login', ['email' => strtoupper($this->admin->email), 'password' => 'ClavePrueba2026'])->assertOk()->assertJsonMissingPath('user.password');
        $this->assertAuthenticatedAs($this->admin);
        $this->postJson('/api/logout')->assertOk();
        $this->assertGuest();
    }

    public function test_roles_are_enforced_by_backend_and_deactivation_revokes_access(): void
    {
        $this->actingAs($this->reception)->getJson('/api/entities/users')->assertForbidden();
        $this->postJson('/api/entities/specialties', ['name' => 'Intruso', 'status' => 'Activo'])->assertForbidden();
        $this->getJson('/api/reports?type=REP05')->assertForbidden();
        $this->actingAs($this->admin)->postJson('/api/appointments', $this->booking())->assertForbidden();
        $this->actingAs($this->physician)->getJson('/api/appointments?mode=history')->assertForbidden();
        $this->physician->update(['status' => 'Inactivo']);
        $this->getJson('/api/references')->assertUnauthorized();
    }

    public function test_user_creation_requires_professional_link_and_hashes_password(): void
    {
        $data = ['name' => 'Nueva cuenta', 'email' => 'NEW@test.local', 'password' => 'UnaClaveSegura2026', 'role' => 'Médico', 'status' => 'Activo'];
        $this->actingAs($this->admin)->postJson('/api/entities/users', $data)->assertUnprocessable()->assertJsonValidationErrors('doctor_id');
        $response = $this->postJson('/api/entities/users', $data + ['doctor_id' => $this->otherDoctor->id])->assertCreated()->assertJsonMissingPath('password');
        $user = User::find($response->json('id'));
        $this->assertTrue(Hash::check($data['password'], $user->password));
        $this->assertSame('new@test.local', $user->email);
        $this->putJson('/api/entities/users/'.$this->admin->id, ['name' => $this->admin->name, 'email' => $this->admin->email, 'role' => 'Recepcionista', 'status' => 'Activo'])->assertUnprocessable();
    }

    public function test_crud_validation_uniqueness_and_status_changes(): void
    {
        $this->actingAs($this->admin)->postJson('/api/entities/specialties', ['name' => 'medicina general', 'status' => 'Activo'])->assertUnprocessable();
        $specialty = $this->postJson('/api/entities/specialties', ['name' => 'Pediatría', 'description' => 'Niños', 'status' => 'Activo'])->assertCreated()->json();
        $this->putJson('/api/entities/specialties/'.$specialty['id'], ['name' => 'Pediatría', 'description' => 'Niños', 'status' => 'Inactivo'])->assertOk();
        $this->postJson('/api/entities/doctors', ['registration' => 'RP-X', 'first_name' => 'Ana', 'last_name' => 'Pérez', 'phone' => '70000000', 'email' => 'ana@test.local', 'status' => 'Activo', 'specialty_ids' => [$specialty['id']]])->assertUnprocessable();
        $doctor = $this->postJson('/api/entities/doctors', ['registration' => 'RP-X', 'first_name' => 'Ana', 'last_name' => 'Pérez', 'phone' => '70000000', 'email' => 'ana@test.local', 'status' => 'Activo', 'specialty_ids' => [$this->specialty->id]])->assertCreated();
        $this->assertSame($this->specialty->id, $doctor->json('specialties.0.id'));
        $this->actingAs($this->reception)->postJson('/api/entities/patients', ['identity' => 'TEST-0', 'first_name' => 'Duplicado', 'last_name' => 'Prueba', 'phone' => '70000000', 'birth_date' => '2030-01-01', 'sex' => 'Femenino', 'status' => 'Activo'])->assertUnprocessable()->assertJsonValidationErrors(['identity', 'birth_date']);
        $this->postJson('/api/entities/patients', ['identity' => 'TEST-NEW', 'first_name' => 'Nueva', 'last_name' => 'Paciente', 'phone' => '70000000', 'birth_date' => '2001-01-01', 'sex' => 'Femenino', 'status' => 'Activo'])->assertCreated();
    }

    public function test_availability_uses_duration_and_full_block_overlap(): void
    {
        Schedule::where('doctor_id', $this->doctor->id)->update(['duration' => 45]);
        Unavailability::create(['doctor_id' => $this->doctor->id, 'starts_at' => '2026-10-26 08:20', 'ends_at' => '2026-10-26 08:30', 'reason' => 'Permiso']);
        $rows = $this->actingAs($this->reception)->getJson('/api/availability?doctor_id='.$this->doctor->id.'&date=2026-10-26')->assertOk()->json();
        $this->assertCount(5, $rows);
        $this->assertSame('08:45', $rows[0]['end']);
        $this->assertSame('Bloqueado', $rows[0]['status']);
        $this->postJson('/api/appointments', $this->booking())->assertUnprocessable();
    }

    public function test_booking_blocks_duplicate_doctor_patient_overlap_and_past_slots(): void
    {
        $a = $this->book();
        $this->assertSame('08:30:00', $a->end_time);
        $this->postJson('/api/appointments', $this->booking(['patient_id' => $this->otherPatient->id]))->assertUnprocessable();
        $this->postJson('/api/appointments', $this->booking(['doctor_id' => $this->otherDoctor->id]))->assertUnprocessable();
        $this->postJson('/api/appointments', $this->booking(['start_time' => '08:15']))->assertUnprocessable();
        $this->travelTo(Carbon::parse('2026-10-26 10:00:00', 'America/La_Paz'));
        $this->postJson('/api/appointments', $this->booking(['start_time' => '09:00']))->assertUnprocessable();
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_booking_requires_active_records_and_correct_specialty(): void
    {
        $this->patient->update(['status' => 'Inactivo']);
        $this->actingAs($this->reception)->postJson('/api/appointments', $this->booking())->assertUnprocessable();
        $this->patient->update(['status' => 'Activo']);
        $s = Specialty::create(['name' => 'Otra', 'status' => 'Activo']);
        $this->postJson('/api/appointments', $this->booking(['specialty_id' => $s->id]))->assertUnprocessable();
        $this->doctor->update(['status' => 'Inactivo']);
        $this->postJson('/api/appointments', $this->booking())->assertUnprocessable();
    }

    public function test_rescheduling_preserves_full_history_and_releases_previous_slot(): void
    {
        $a = $this->book();
        $this->putJson('/api/appointments/'.$a->id.'/reschedule', ['doctor_id' => $this->otherDoctor->id, 'date' => '2026-10-26', 'start_time' => '09:00'])->assertOk();
        $this->putJson('/api/appointments/'.$a->id.'/reschedule', ['doctor_id' => $this->doctor->id, 'date' => '2026-10-26', 'start_time' => '10:00'])->assertOk();
        $events = $a->events()->get();
        $this->assertCount(3, $events);
        $this->assertSame('08:00:00', $events[1]->details['before']['start_time']);
        $this->postJson('/api/appointments', $this->booking(['patient_id' => $this->otherPatient->id]))->assertCreated();
    }

    public function test_cancel_requires_reason_releases_slot_and_prevents_later_changes(): void
    {
        $a = $this->book();
        $this->postJson('/api/appointments/'.$a->id.'/cancel', [])->assertUnprocessable();
        $this->postJson('/api/appointments/'.$a->id.'/cancel', ['reason' => 'Paciente de viaje'])->assertOk()->assertJsonPath('status', 'Cancelada');
        $this->postJson('/api/appointments/'.$a->id.'/confirm', [])->assertUnprocessable();
        $this->postJson('/api/appointments', $this->booking())->assertCreated();
        $this->assertSame('Paciente de viaje', $a->fresh()->cancellation_reason);
    }

    public function test_attendance_does_not_change_status_and_absence_requires_end_time(): void
    {
        $a = $this->book();
        $this->postJson('/api/appointments/'.$a->id.'/confirm', [])->assertOk()->assertJsonPath('status', 'Confirmada');
        $this->postJson('/api/appointments/'.$a->id.'/absent', [])->assertUnprocessable();
        $this->postJson('/api/appointments/'.$a->id.'/attendance', [])->assertOk()->assertJsonPath('status', 'Confirmada');
        $this->assertNotNull($a->fresh()->attendance_at);
        $this->travelTo(Carbon::parse('2026-10-26 10:00:00', 'America/La_Paz'));
        $this->postJson('/api/appointments/'.$a->id.'/absent', [])->assertUnprocessable();
        $b = Appointment::create($this->booking(['patient_id' => $this->otherPatient->id, 'start_time' => '09:00']) + ['end_time' => '09:30', 'status' => 'Programada', 'created_by' => $this->reception->id]);
        $this->postJson('/api/appointments/'.$b->id.'/absent', [])->assertOk()->assertJsonPath('status', 'Inasistencia');
    }

    public function test_physician_only_sees_and_attends_own_agenda(): void
    {
        $own = $this->book();
        $other = $this->book(['doctor_id' => $this->otherDoctor->id, 'patient_id' => $this->otherPatient->id]);
        $response = $this->actingAs($this->physician)->getJson('/api/appointments?mode=agenda&doctor_id='.$this->otherDoctor->id)->assertOk();
        $response->assertJsonCount(1)->assertJsonPath('0.id', $own->id);
        $this->postJson('/api/appointments/'.$other->id.'/attend', [])->assertForbidden();
        $this->postJson('/api/appointments/'.$own->id.'/attend', [])->assertOk()->assertJsonPath('status', 'Atendida');
        $this->postJson('/api/appointments/'.$own->id.'/attend', [])->assertUnprocessable();
    }

    public function test_unavailability_preview_and_affected_list_do_not_modify_appointments(): void
    {
        $a = $this->book();
        $d = ['doctor_id' => $this->doctor->id, 'starts_at' => '2026-10-26T08:15', 'ends_at' => '2026-10-26T09:15', 'reason' => 'Permiso'];
        $this->actingAs($this->admin)->postJson('/api/unavailabilities/preview', $d)->assertOk()->assertJsonCount(1, 'affected');
        $this->assertDatabaseCount('unavailabilities', 0);
        $this->postJson('/api/unavailabilities', $d)->assertCreated()->assertJsonCount(1, 'affected');
        $this->actingAs($this->reception)->getJson('/api/appointments?mode=affected')->assertOk()->assertJsonPath('0.id', $a->id)->assertJsonPath('0.blocks.0.reason', 'Permiso');
        $this->assertSame('Programada', $a->fresh()->status);
    }

    public function test_schedule_changes_validate_ranges_and_preserve_existing_bookings(): void
    {
        $this->book();
        $d = ['doctor_id' => $this->doctor->id, 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '12:00', 'duration' => 30];
        $this->actingAs($this->admin)->postJson('/api/schedules', array_replace($d, ['end_time' => '07:00']))->assertUnprocessable();
        $this->postJson('/api/schedules', array_replace($d, ['start_time' => '09:00']))->assertUnprocessable();
        $this->postJson('/api/schedules', $d)->assertOk();
        $this->postJson('/api/schedules', array_replace($d, ['weekday' => 0]))->assertCreated();
    }

    public function test_history_filters_seven_reports_csv_and_empty_percentage(): void
    {
        $a = $this->book();
        $this->actingAs($this->admin)->getJson('/api/appointments?mode=history&q=TEST-0&status=Programada&date=2026-10-26')->assertOk()->assertJsonCount(1)->assertJsonPath('0.events.0.action', 'Creación');
        foreach (range(1, 7) as $i) {
            $query = http_build_query(['type' => 'REP0'.$i, 'date' => '2026-10-26', 'from' => '2026-10-01', 'to' => '2026-10-31']);
            $this->getJson('/api/reports?'.$query)->assertOk()->assertJsonPath('summary.total', $i === 5 ? 2 : 1);
        }
        $this->getJson('/api/reports?type=REP06&from=2027-01-01&to=2027-01-02')->assertOk()->assertJsonPath('summary.Atendida.percentage', 0);
        $this->patient->update(['first_name' => '=HYPERLINK("bad")']);
        $csv = $this->get('/api/reports?type=REP05&format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->getJson('/api/reports?type=REP06&from=2026-10-30&to=2026-10-01')->assertUnprocessable();
    }
}
