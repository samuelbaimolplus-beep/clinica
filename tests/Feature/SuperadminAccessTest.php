<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BookingRequest;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Models\RescheduleRequest;
use App\Models\Schedule;
use App\Models\Specialty;
use App\Models\User;
use App\Support\ClinicAccess;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SuperadminAccessTest extends TestCase
{
    use DatabaseTransactions;

    private User $superadmin;

    private User $admin;

    private User $reception;

    private User $patientUser;

    private Doctor $doctor;

    private Patient $patient;

    private Specialty $specialty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('clinica_vida_salud_test', config('database.connections.pgsql.database'));
        config(['clinic.appointments_enabled' => true, 'wizpay.key' => null]);
        $this->travelTo(now()->setDate(2026, 11, 9)->setTime(7, 0));
        foreach (['superadmin' => 'Superadmin', 'admin' => 'Administrador', 'reception' => 'Recepcionista'] as $property => $role) {
            $this->$property = User::create(['name' => $role, 'email' => $property.'@supervision.test', 'password' => 'PruebaSegura2026', 'role' => $role, 'status' => 'Activo']);
        }
        $this->specialty = Specialty::create(['name' => 'Especialidad supervisión', 'status' => 'Activo', 'fee_minor' => 10000]);
        $this->doctor = Doctor::create(['registration' => 'SUP-01', 'first_name' => 'Médica', 'last_name' => 'Supervisión', 'email' => 'doctor@supervision.test', 'phone' => '70000000', 'status' => 'Activo']);
        $this->doctor->specialties()->attach($this->specialty);
        Schedule::create(['doctor_id' => $this->doctor->id, 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '12:00', 'duration' => 30]);
        $this->patient = Patient::create(['first_name' => 'Paciente', 'last_name' => 'Sin documento', 'birth_date' => '2000-01-01', 'sex' => 'Femenino', 'phone' => '70000001', 'status' => 'Activo']);
        $this->patientUser = User::create(['name' => 'Paciente', 'email' => 'patient@supervision.test', 'password' => 'PruebaSegura2026', 'role' => 'Paciente', 'patient_id' => $this->patient->id, 'status' => 'Activo']);
        $this->patientUser->forceFill(['email_verified_at' => now()])->save();
    }

    private function appointment(): Appointment
    {
        return Appointment::create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'specialty_id' => $this->specialty->id,
            'created_by' => $this->reception->id, 'date' => '2026-11-09', 'start_time' => '08:00', 'end_time' => '08:30', 'status' => 'Programada']);
    }

    public function test_superadmin_logs_in_and_has_all_views_while_other_roles_remain_separate(): void
    {
        $this->postJson('/api/login', ['email' => $this->superadmin->email, 'password' => 'PruebaSegura2026'])->assertOk();
        $session = $this->getJson('/api/session')->assertOk()->assertJsonPath('user.role', 'Superadmin');
        foreach (range(1, 26) as $number) {
            $this->assertContains('CU'.str_pad($number, 2, '0', STR_PAD_LEFT), $session->json('access.Superadmin'));
        }
        $this->assertContains('CU18', ClinicAccess::views('Paciente'));
        $this->assertNotContains('CU18', ClinicAccess::views('Administrador'));
        $this->assertNotContains('CU03', ClinicAccess::views('Recepcionista'));
        $this->assertSame(['CU01', 'CU02', 'CU12', 'CU14'], ClinicAccess::views('Médico'));
    }

    public function test_superadmin_can_read_all_modules_and_both_modalities(): void
    {
        $this->appointment();
        $this->actingAs($this->superadmin);
        foreach (['entities/users', 'entities/doctors', 'entities/specialties', 'entities/patients', 'references',
            'schedules?doctor_id='.$this->doctor->id, 'schedule-impacts', 'agenda-adjustments?date=2026-11-09',
            'appointments?mode=agenda', 'appointments?mode=attend', 'appointments?mode=history', 'reschedule-requests',
            'online-bookings', 'refunds', 'audit', 'audit/filters', 'supervision/patient-accounts', 'supervision/payments', 'reception-inbox',
            'reports?type=REP05'] as $endpoint) {
            $this->getJson('/api/'.$endpoint)->assertOk();
        }
        foreach (['online', 'presential'] as $modality) {
            $this->getJson('/api/medical-availability?'.http_build_query(['specialty_id' => $this->specialty->id, 'date' => '2026-11-09', 'modality' => $modality]))
                ->assertOk()->assertJsonPath('modality', $modality);
        }
        $this->getJson('/api/supervision/patient-accounts')->assertJsonMissingPath('0.password')->assertJsonPath('0.patient.id', $this->patient->id);
    }

    public function test_admin_cannot_create_edit_or_disable_a_superadmin(): void
    {
        $this->actingAs($this->admin);
        $this->getJson('/api/entities/users')->assertOk()->assertJsonMissing(['id' => $this->superadmin->id]);
        $this->getJson('/api/entities/users/'.$this->superadmin->id)->assertNotFound();
        $this->patchJson('/api/entities/users/'.$this->superadmin->id.'/status', ['status' => 'Inactivo'])->assertForbidden();
        $this->putJson('/api/entities/users/'.$this->superadmin->id, ['name' => 'Modificado'])->assertForbidden();
        $this->postJson('/api/entities/users', ['name' => 'Escalada', 'email' => 'escalada@supervision.test', 'password' => 'PruebaSegura2026', 'role' => 'Superadmin', 'status' => 'Activo'])->assertUnprocessable();
        $this->assertSame('Activo', $this->superadmin->fresh()->status);
    }

    public function test_only_superadmin_manages_other_superadmins_but_cannot_remove_own_access(): void
    {
        $this->actingAs($this->superadmin);
        $response = $this->postJson('/api/entities/users', ['name' => 'Equipo', 'email' => 'equipo@supervision.test', 'password' => 'PruebaSegura2026', 'role' => 'Superadmin', 'status' => 'Activo'])->assertCreated();
        $this->assertTrue(Hash::check('PruebaSegura2026', User::findOrFail($response->json('id'))->password));
        $this->patchJson('/api/entities/users/'.$this->superadmin->id.'/status', ['status' => 'Inactivo'])->assertUnprocessable();
        $this->patchJson('/api/entities/users/'.$response->json('id').'/status', ['status' => 'Inactivo'])->assertOk();
    }

    public function test_supervision_endpoints_reject_every_other_role_and_visitors(): void
    {
        $this->getJson('/api/supervision/payments')->assertUnauthorized();
        foreach ([$this->admin, $this->reception, $this->patientUser] as $user) {
            $this->actingAs($user);
            $this->getJson('/api/supervision/payments')->assertForbidden();
            $this->getJson('/api/supervision/patient-accounts')->assertForbidden();
        }
    }

    public function test_superadmin_does_not_impersonate_a_physician_or_patient(): void
    {
        $a = $this->appointment();
        $this->actingAs($this->superadmin);
        $this->postJson('/api/appointments/'.$a->id.'/attend', ['consultation_finished' => true])->assertForbidden();
        $this->postJson('/api/online-bookings', [])->assertForbidden();
        $this->postJson('/api/appointments/'.$a->id.'/reschedule-request', ['reason' => 'Cambio'])->assertForbidden();
        $this->assertSame('Programada', $a->fresh()->status);
        $this->assertDatabaseCount('booking_requests', 0);
    }

    public function test_patient_request_appears_in_reception_notice_and_disappears_on_resolution(): void
    {
        $a = $this->appointment();
        $request = $this->actingAs($this->patientUser)->postJson('/api/appointments/'.$a->id.'/reschedule-request', ['reason' => 'Otra fecha'])->assertCreated();
        $this->assertSame('08:00:00', $a->fresh()->start_time);
        $this->getJson('/api/reception-inbox')->assertForbidden();
        $this->actingAs($this->reception)->getJson('/api/reception-inbox')->assertOk()->assertJsonPath('pending', 1)->assertJsonPath('latest_id', $request->json('id'));
        $this->actingAs($this->superadmin)->postJson('/api/reschedule-requests/'.$request->json('id').'/reject', ['response' => 'Se coordinó otra alternativa'])->assertOk();
        $this->getJson('/api/reception-inbox')->assertOk()->assertJsonPath('pending', 0);
        $this->assertSame('rejected', RescheduleRequest::findOrFail($request->json('id'))->status);
    }

    public function test_online_supervision_is_read_only_and_cannot_retry_someone_elses_payment(): void
    {
        $booking = BookingRequest::create(['idempotency_key' => fake()->uuid(), 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
            'specialty_id' => $this->specialty->id, 'created_by' => $this->patientUser->id, 'date' => '2026-11-09', 'start_time' => '10:30', 'end_time' => '11:00',
            'modality' => 'online', 'amount_minor' => 10000, 'status' => 'held', 'expires_at' => now()->addMinutes(5), 'deadline_at' => now()->addMinutes(10)]);
        $this->actingAs($this->superadmin)->getJson('/api/online-bookings')->assertOk()->assertJsonPath('0.id', $booking->id);
        $this->getJson('/api/online-bookings/'.$booking->id)->assertOk()->assertJsonPath('booking.status', 'held');
        $this->assertSame('held', $booking->fresh()->status);
        $this->postJson('/api/online-bookings/'.$booking->id.'/retry')->assertForbidden();
        $this->actingAs($this->reception)->getJson('/api/online-bookings')->assertForbidden();
        $this->getJson('/api/online-bookings/'.$booking->id)->assertForbidden();
    }

    public function test_superadmin_cannot_resolve_own_refund_and_can_inspect_real_payment_relations(): void
    {
        $a = $this->appointment();
        $payment = Payment::create(['method' => 'cash', 'amount_minor' => 10000, 'status' => 'confirmed', 'confirmed_by' => $this->reception->id, 'confirmed_at' => now()]);
        $a->update(['payment_id' => $payment->id, 'status' => 'Cancelada', 'cancelled_at' => now(), 'cancellation_cause' => 'clinic']);
        $request = $this->actingAs($this->superadmin)->postJson('/api/appointments/'.$a->id.'/refund', ['reason' => 'La clínica no puede atender'])->assertCreated();
        $this->postJson('/api/refunds/'.$request->json('id').'/decision', ['decision' => 'approve'])->assertForbidden();
        $this->assertSame('pending', RefundRequest::findOrFail($request->json('id'))->status);
        $this->getJson('/api/supervision/payments')->assertOk()->assertJsonPath('data.0.id', $payment->id)->assertJsonPath('data.0.appointments.0.patient.id', $this->patient->id)->assertJsonMissingPath('data.0.qr_image');
        $this->actingAs($this->admin)->postJson('/api/refunds/'.$request->json('id').'/decision', ['decision' => 'approve'])->assertOk();
    }

    public function test_history_search_finds_patients_without_identity(): void
    {
        $a = $this->appointment();
        $this->actingAs($this->superadmin)->getJson('/api/appointments?mode=history&q=Sin%20documento')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $a->id);
    }

    public function test_date_report_accepts_a_period_and_rejects_invalid_or_missing_dates(): void
    {
        $a = $this->appointment();
        $this->actingAs($this->superadmin)->getJson('/api/reports?type=REP01&from=2026-11-01&to=2026-11-30')
            ->assertOk()->assertJsonPath('summary.total', 1)->assertJsonPath('rows.0.0', 'CIT-'.str_pad($a->id, 4, '0', STR_PAD_LEFT));
        $this->getJson('/api/reports?type=REP01&from=2026-11-30&to=2026-11-01')->assertUnprocessable();
        $this->getJson('/api/reports?type=REP01')->assertUnprocessable();
    }
}
