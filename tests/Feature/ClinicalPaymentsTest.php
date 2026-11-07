<?php

namespace Tests\Feature;

use App\Models\AgendaAdjustment;
use App\Models\Appointment;
use App\Models\BookingRequest;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\Specialty;
use App\Models\User;
use App\Services\Payments\OnlineBookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClinicalPaymentsTest extends TestCase
{
    use DatabaseTransactions;

    private User $reception;

    private User $admin;

    private User $patientUser;

    private User $physician;

    private Patient $patient;

    private Doctor $doctor;

    private Specialty $specialty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('clinica_vida_salud_test', config('database.connections.pgsql.database'));
        config(['clinic.appointments_enabled' => true, 'wizpay.key' => 'wtest_unit_tests_only']);
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-11-09 07:00', 'America/La_Paz'));
        $this->specialty = Specialty::create(['name' => 'Especialidad pagos', 'status' => 'Activo', 'fee_minor' => 12550]);
        $this->doctor = Doctor::create(['registration' => 'PAY-01', 'first_name' => 'Ana', 'last_name' => 'Médica', 'email' => 'doctor-pay@test.local', 'phone' => '70000000', 'status' => 'Activo']);
        $this->doctor->specialties()->attach($this->specialty);
        Schedule::create(['doctor_id' => $this->doctor->id, 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '12:00', 'duration' => 30]);
        $this->patient = Patient::create(['identity' => 'PAY-001', 'first_name' => 'María', 'last_name' => 'Paciente', 'phone' => '70000001', 'birth_date' => '2000-01-01', 'sex' => 'Femenino', 'status' => 'Activo']);
        foreach (['reception' => 'Recepcionista', 'admin' => 'Administrador', 'patientUser' => 'Paciente', 'physician' => 'Médico'] as $key => $role) {
            $this->$key = User::create(['name' => $key, 'email' => $key.'@payments.test', 'password' => 'PruebaSegura2026', 'role' => $role, 'status' => 'Activo',
                'patient_id' => $role === 'Paciente' ? $this->patient->id : null, 'doctor_id' => $role === 'Médico' ? $this->doctor->id : null]);
        }
        $this->patientUser->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($this->reception);
    }

    private function data(array $changes = []): array
    {
        return array_replace(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'specialty_id' => $this->specialty->id,
            'date' => '2026-11-09', 'start_time' => '08:00', 'idempotency_key' => (string) Str::uuid(),
            'payment_method' => 'cash', 'payment_amount_minor' => 12550, 'payment_confirmed' => true], $changes);
    }

    private function book(array $changes = []): Appointment
    {
        $r = $this->actingAs($this->reception)->postJson('/api/appointments', $this->data($changes))->assertCreated();

        return Appointment::findOrFail($r->json('id'));
    }

    private function fakeGateway(string $state = 'APROBADO', bool $wrongAmount = false, bool $loseGenerationResponse = false, ?string $secondState = null): void
    {
        Http::fake(function ($request) use ($state, $wrongAmount, $loseGenerationResponse, $secondState) {
            if (str_ends_with($request->url(), '/qr/generate')) {
                if ($loseGenerationResponse) {
                    throw new ConnectionException('Tiempo de espera de prueba');
                }

                return Http::response(['reference' => 'WP-'.$request['order_id'], 'qr' => ['numero_referencia' => 12345, 'imagen' => 'aW1hZ2U=', 'monto' => $request['amount'], 'moneda' => 'BOB']], 201);
            }
            $payment = Payment::where('reference', basename($request->url()))->firstOrFail();

            return Http::response(['reference' => $payment->reference, 'order_id' => 'CVS-'.$payment->booking_request_id.'-'.$payment->attempt,
                'amount' => $wrongAmount ? 1 : $payment->amount_minor / 100, 'status' => $payment->attempt === 2 ? ($secondState ?? $state) : $state]);
        });
    }

    private function startOnline(): int
    {
        $data = $this->data(['start_time' => '10:30']);
        unset($data['patient_id'], $data['payment_method'], $data['payment_amount_minor'], $data['payment_confirmed']);

        return $this->actingAs($this->patientUser)->postJson('/api/online-bookings', $data)->assertOk()->json('booking.id');
    }

    public function test_no_ficha_without_verified_full_payment_and_duplicate_submission_is_idempotent(): void
    {
        $this->postJson('/api/appointments', $this->data(['payment_confirmed' => false]))->assertUnprocessable();
        $this->postJson('/api/appointments', $this->data(['payment_amount_minor' => 1]))->assertUnprocessable();
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('payments', 0);
        $data = $this->data();
        $id = $this->postJson('/api/appointments', $data)->assertCreated()->json('id');
        $this->postJson('/api/appointments', $data)->assertCreated()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_bank_reference_is_required_and_cannot_be_reused(): void
    {
        $this->postJson('/api/appointments', $this->data(['payment_method' => 'qr']))->assertUnprocessable();
        $this->book(['payment_method' => 'qr', 'bank_reference' => 'BANCO-001']);
        $this->postJson('/api/appointments', $this->data(['start_time' => '09:00', 'payment_method' => 'transfer', 'bank_reference' => 'BANCO-001']))->assertUnprocessable();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_compatible_demand_block_is_consumed_only_after_payment(): void
    {
        $block = AgendaAdjustment::create(['doctor_id' => $this->doctor->id, 'specialty_id' => $this->specialty->id, 'date' => '2026-11-09', 'start_time' => '08:00', 'end_time' => '08:30', 'kind' => 'presential_block', 'status' => 'active', 'created_by' => $this->reception->id]);
        $this->postJson('/api/appointments', $this->data(['payment_confirmed' => false]))->assertUnprocessable();
        $this->assertSame('active', $block->fresh()->status);
        $a = $this->book();
        $this->assertSame('consumed', $block->fresh()->status);
        $this->assertSame($a->id, $block->fresh()->appointment_id);
    }

    public function test_online_hold_hides_slot_and_confirmed_server_payment_creates_exactly_one_ficha(): void
    {
        $this->fakeGateway();
        $id = $this->startOnline();
        $this->assertDatabaseCount('appointments', 0);
        $this->getJson('/api/medical-availability?'.http_build_query(['specialty_id' => $this->specialty->id, 'date' => '2026-11-09']))->assertOk()->assertJsonMissing(['start_time' => '10:30']);
        $this->travel(31)->seconds();
        $this->postJson('/api/online-bookings/'.$id.'/status')->assertOk()->assertJsonPath('booking.status', 'completed')->assertJsonPath('appointment.status', 'Programada');
        $this->postJson('/api/online-bookings/'.$id.'/status')->assertOk();
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('confirmed', Payment::first()->status);
    }

    public function test_pending_payment_keeps_hold_after_deadline_and_blocks_second_charge(): void
    {
        $this->fakeGateway('PENDIENTE');
        $id = $this->startOnline();
        $this->travel(11)->minutes();
        $this->postJson('/api/online-bookings/'.$id.'/status')->assertOk()->assertJsonPath('booking.status', 'processing');
        $this->postJson('/api/online-bookings/'.$id.'/retry')->assertUnprocessable();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_two_failed_attempts_expire_and_release_without_ficha(): void
    {
        $this->fakeGateway('RECHAZADO');
        $id = $this->startOnline();
        $this->travel(5)->minutes();
        $this->postJson('/api/online-bookings/'.$id.'/status')->assertOk()->assertJsonPath('booking.status', 'held');
        $this->postJson('/api/online-bookings/'.$id.'/retry')->assertOk()->assertJsonPath('booking.attempt', 2);
        $this->travel(5)->minutes();
        $this->postJson('/api/online-bookings/'.$id.'/status')->assertOk()->assertJsonPath('booking.status', 'expired');
        $this->postJson('/api/online-bookings/'.$id.'/retry')->assertUnprocessable();
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_payment_with_wrong_amount_never_formalizes(): void
    {
        $this->fakeGateway('APROBADO', true);
        $id = $this->startOnline();
        $this->travel(31)->seconds();
        $this->postJson('/api/online-bookings/'.$id.'/status')->assertOk()->assertJsonPath('payment.status', 'unknown');
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_missing_gateway_configuration_does_not_create_a_hold(): void
    {
        config(['wizpay.key' => null]);
        $this->actingAs($this->patientUser)->postJson('/api/online-bookings', $this->data(['start_time' => '10:30']))->assertUnprocessable();
        $this->assertDatabaseCount('booking_requests', 0);
        Http::assertNothingSent();
    }

    public function test_patient_reschedule_request_preserves_original_until_reception_accepts(): void
    {
        $a = $this->book();
        $this->actingAs($this->patientUser)->postJson('/api/appointments/'.$a->id.'/reschedule-request', ['reason' => 'Solicito el lunes siguiente'])->assertCreated();
        $this->assertSame('08:00:00', $a->fresh()->start_time);
        $this->postJson('/api/appointments/'.$a->id.'/cancel', ['reason' => 'Cancelar', 'cause' => 'patient'])->assertForbidden();
        $this->actingAs($this->reception)->putJson('/api/appointments/'.$a->id.'/reschedule', ['doctor_id' => $this->doctor->id, 'date' => '2026-11-09', 'start_time' => '09:00', 'cause' => 'patient', 'patient_accepted' => true, 'change_reason' => 'Acepta por WhatsApp'])->assertOk()->assertJsonPath('payment_id', $a->payment_id);
        $this->assertDatabaseHas('reschedule_requests', ['appointment_id' => $a->id, 'status' => 'accepted']);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_refund_request_does_not_cancel_and_approval_does_not_complete(): void
    {
        $a = $this->book();
        $this->actingAs($this->patientUser)->postJson('/api/appointments/'.$a->id.'/refund', ['reason' => 'Cambio'])->assertUnprocessable();
        $this->assertSame('Programada', $a->fresh()->status);
        $this->actingAs($this->reception)->postJson('/api/appointments/'.$a->id.'/cancel', ['cause' => 'clinic', 'reason' => 'Médico indisponible'])->assertOk();
        $id = $this->actingAs($this->patientUser)->postJson('/api/appointments/'.$a->id.'/refund', ['reason' => 'Devolución completa'])->assertCreated()->assertJsonPath('status', 'pending')->json('id');
        $this->postJson('/api/appointments/'.$a->id.'/refund', ['reason' => 'Duplicado'])->assertUnprocessable();
        $this->actingAs($this->admin)->postJson('/api/refunds/'.$id.'/decision', ['decision' => 'approve'])->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('completed_at', null);
        $this->postJson('/api/refunds/'.$id.'/complete', ['executor' => 'Caja Clínica', 'method' => 'Efectivo', 'amount_minor' => 12550, 'completed_at' => now()->toIso8601String()])->assertOk()->assertJsonPath('status', 'completed');
        $this->postJson('/api/refunds/'.$id.'/decision', ['decision' => 'approve'])->assertUnprocessable();
    }

    public function test_fifteen_minute_grace_uses_cancellation_time_even_when_requested_later(): void
    {
        $a = $this->book();
        $this->travel(14)->minutes();
        $this->postJson('/api/appointments/'.$a->id.'/cancel', ['cause' => 'patient', 'reason' => 'No podré acudir'])->assertOk();
        $this->travel(2)->hours();
        $this->postJson('/api/appointments/'.$a->id.'/refund', ['reason' => 'Dentro de gracia al cancelar'])->assertCreated()->assertJsonPath('status', 'pending');
    }

    public function test_voluntary_cancellation_outside_grace_is_recorded_ineligible(): void
    {
        $a = $this->book();
        $this->travel(16)->minutes();
        $this->postJson('/api/appointments/'.$a->id.'/cancel', ['cause' => 'patient', 'reason' => 'No podré acudir'])->assertOk();
        $this->postJson('/api/appointments/'.$a->id.'/refund', ['reason' => 'Solicito devolución'])->assertCreated()->assertJsonPath('status', 'ineligible');
    }

    public function test_followup_requires_recommendation_and_loses_exemption_for_patient_change(): void
    {
        $a = $this->book();
        $this->postJson('/api/appointments/'.$a->id.'/attendance')->assertOk()->assertJsonPath('status', 'Confirmada');
        $this->actingAs($this->physician)->postJson('/api/appointments/'.$a->id.'/attend', ['consultation_finished' => true, 'followup_suggested_at' => '2026-11-16 08:00'])->assertOk();
        $data = $this->data(['date' => '2026-11-16', 'parent_appointment_id' => $a->id]);
        unset($data['payment_method'], $data['payment_confirmed'], $data['payment_amount_minor']);
        $id = $this->actingAs($this->reception)->postJson('/api/appointments', $data)->assertCreated()->assertJsonPath('complimentary', true)->json('id');
        $change = ['doctor_id' => $this->doctor->id, 'date' => '2026-11-16', 'start_time' => '09:00', 'cause' => 'patient', 'patient_accepted' => true, 'change_reason' => 'Solicita otra hora'];
        $this->putJson('/api/appointments/'.$id.'/reschedule', $change)->assertUnprocessable();
        $this->putJson('/api/appointments/'.$id.'/reschedule', $change + ['payment_method' => 'cash', 'payment_confirmed' => true, 'payment_amount_minor' => 12550])->assertOk()->assertJsonPath('complimentary', false);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_late_free_followup_requires_full_payment_before_attendance(): void
    {
        $original = $this->book();
        $original->update(['status' => 'Atendida', 'followup_suggested_at' => '2026-11-16 08:00']);
        $data = $this->data(['date' => '2026-11-16', 'parent_appointment_id' => $original->id]);
        unset($data['payment_method'], $data['payment_amount_minor'], $data['payment_confirmed']);
        $id = $this->postJson('/api/appointments', $data)->assertCreated()->assertJsonPath('complimentary', true)->json('id');
        $this->travelTo(Carbon::parse('2026-11-16 08:01', 'America/La_Paz'));
        $this->postJson('/api/appointments/'.$id.'/attendance')->assertUnprocessable();
        $this->assertNull(Appointment::findOrFail($id)->attendance_at);
        $payment = ['payment_method' => 'cash', 'payment_amount_minor' => 12550, 'payment_confirmed' => true];
        $this->postJson('/api/appointments/'.$id.'/attendance', $payment)->assertOk()->assertJsonPath('complimentary', false)->assertJsonPath('status', 'Confirmada');
        $this->postJson('/api/appointments/'.$id.'/attendance', $payment)->assertUnprocessable();
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_clinic_reschedule_preserves_followup_exemption_and_requires_acceptance(): void
    {
        $original = $this->book();
        $original->update(['status' => 'Atendida', 'followup_suggested_at' => '2026-11-16 08:00']);
        $data = $this->data(['date' => '2026-11-16', 'parent_appointment_id' => $original->id]);
        unset($data['payment_method'], $data['payment_amount_minor'], $data['payment_confirmed']);
        $id = $this->postJson('/api/appointments', $data)->assertCreated()->json('id');
        $change = ['doctor_id' => $this->doctor->id, 'date' => '2026-11-16', 'start_time' => '09:00', 'cause' => 'clinic', 'change_reason' => 'Cambio de turno por la clínica'];
        $this->putJson('/api/appointments/'.$id.'/reschedule', $change)->assertUnprocessable();
        $this->putJson('/api/appointments/'.$id.'/reschedule', $change + ['patient_accepted' => true])->assertOk()->assertJsonPath('complimentary', true);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_patient_cannot_read_or_change_another_patients_clinical_records(): void
    {
        $a = $this->book();
        $other = Patient::create(['identity' => 'PAY-OTHER', 'first_name' => 'Otro', 'last_name' => 'Paciente', 'phone' => '70000005', 'birth_date' => '2000-01-01', 'sex' => 'Masculino', 'status' => 'Activo']);
        $this->patientUser->forceFill(['patient_id' => $other->id])->save();
        $this->actingAs($this->patientUser)->getJson('/api/appointments?mode=history')->assertOk()->assertJsonCount(0);
        $this->postJson('/api/appointments/'.$a->id.'/reschedule-request', ['reason' => 'Intento ajeno'])->assertForbidden();
        $this->assertDatabaseHas('audit_events', ['module' => 'clinical_access', 'action' => 'denied', 'user_id' => $this->patientUser->id]);
        $this->assertDatabaseCount('reschedule_requests', 0);
    }

    public function test_hold_prevents_schedule_and_absence_changes_during_payment(): void
    {
        $this->fakeGateway('PENDIENTE');
        $this->startOnline();
        $this->actingAs($this->admin)->postJson('/api/unavailabilities', ['doctor_id' => $this->doctor->id, 'starts_at' => '2026-11-09 10:00', 'ends_at' => '2026-11-09 12:00', 'reason' => 'Cambio'])->assertUnprocessable();
        $shift = Schedule::where('doctor_id', $this->doctor->id)->firstOrFail();
        $this->putJson('/api/schedules/'.$shift->id, ['doctor_id' => $this->doctor->id, 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '13:00', 'duration' => 30])->assertUnprocessable();
        $this->assertDatabaseCount('unavailabilities', 0);
        $this->assertSame('08:00:00', $shift->fresh()->start_time);
    }

    public function test_webhook_requires_secret_sandbox_and_backend_confirmation_and_is_idempotent(): void
    {
        config(['wizpay.webhook_key' => 'secret-for-tests-only']);
        $this->fakeGateway();
        $id = $this->startOnline();
        $payment = Payment::where('booking_request_id', $id)->firstOrFail();
        $payload = ['environment' => 'SANDBOX', 'event' => 'payment.qr.success', 'reference' => $payment->reference,
            'order_id' => 'CVS-'.$id.'-1', 'qr_id' => '12345', 'amount' => 125.50, 'currency' => 'BOB'];
        $this->postJson('/webhooks/wizpay', $payload)->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer secret-for-tests-only')->postJson('/webhooks/wizpay', array_replace($payload, ['environment' => 'PRODUCCION']))->assertUnprocessable();
        $this->postJson('/webhooks/wizpay', $payload)->assertOk();
        $this->postJson('/webhooks/wizpay', $payload)->assertOk();
        $this->assertDatabaseCount('appointments', 1);
        $this->assertSame('completed', BookingRequest::findOrFail($id)->status);
    }

    public function test_confirmed_presence_can_be_cancelled_by_reception_as_defined_in_v6(): void
    {
        $a = $this->book();
        $this->postJson('/api/appointments/'.$a->id.'/attendance')->assertOk()->assertJsonPath('status', 'Confirmada');
        $this->postJson('/api/appointments/'.$a->id.'/cancel', ['reason' => 'No se realizará consulta por causa clínica', 'cause' => 'clinic'])->assertOk()->assertJsonPath('status', 'Cancelada');
        $this->postJson('/api/appointments/'.$a->id.'/refund', ['reason' => 'Cancelación clínica'])->assertCreated()->assertJsonPath('status', 'pending');
    }

    public function test_legacy_unverified_payment_is_not_invented_and_must_be_checked_before_attention(): void
    {
        $a = Appointment::create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'specialty_id' => $this->specialty->id,
            'date' => '2026-11-09', 'start_time' => '08:00', 'end_time' => '08:30', 'status' => 'Confirmada',
            'attendance_at' => now(), 'created_by' => $this->reception->id]);
        $arrival = $a->fresh()->attendance_at;
        $this->actingAs($this->physician)->postJson('/api/appointments/'.$a->id.'/attend', ['consultation_finished' => true])->assertUnprocessable();
        $this->assertDatabaseCount('payments', 0);
        $this->actingAs($this->reception)->postJson('/api/appointments/'.$a->id.'/attendance', ['payment_method' => 'cash', 'payment_confirmed' => true, 'payment_amount_minor' => 12550])->assertOk();
        $this->assertSame($arrival, $a->fresh()->attendance_at);
        $this->actingAs($this->physician)->postJson('/api/appointments/'.$a->id.'/attend', ['consultation_finished' => true])->assertOk()->assertJsonPath('status', 'Atendida');
    }

    public function test_punctual_free_followup_stays_free_while_waiting_for_the_physician(): void
    {
        $original = $this->book();
        $original->update(['status' => 'Atendida', 'followup_suggested_at' => '2026-11-16 08:00']);
        $data = $this->data(['date' => '2026-11-16', 'parent_appointment_id' => $original->id]);
        unset($data['payment_method'], $data['payment_amount_minor'], $data['payment_confirmed']);
        $id = $this->postJson('/api/appointments', $data)->assertCreated()->json('id');
        $this->travelTo(Carbon::parse('2026-11-16 07:59', 'America/La_Paz'));
        $this->postJson('/api/appointments/'.$id.'/attendance')->assertOk()->assertJsonPath('complimentary', true);
        $this->travel(1)->hours();
        $this->actingAs($this->physician)->postJson('/api/appointments/'.$id.'/attend', ['consultation_finished' => true])->assertOk()->assertJsonPath('status', 'Atendida')->assertJsonPath('complimentary', true);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_authenticated_webhook_recovers_a_lost_generation_response_without_charging_again(): void
    {
        config(['wizpay.webhook_key' => 'secret-recovery-test']);
        $this->fakeGateway('APROBADO', false, true);
        $id = $this->startOnline();
        $payment = Payment::where('booking_request_id', $id)->firstOrFail();
        $this->assertSame('unknown', $payment->status);
        $this->assertNull($payment->reference);
        $this->postJson('/api/online-bookings/'.$id.'/retry')->assertUnprocessable();
        $this->withHeader('Authorization', 'Bearer secret-recovery-test')->postJson('/webhooks/wizpay', ['environment' => 'SANDBOX',
            'event' => 'payment.qr.success', 'reference' => 'WP-RECOVERED', 'order_id' => 'CVS-'.$id.'-1', 'qr_id' => '12345', 'amount' => 125.50, 'currency' => 'BOB'])->assertOk();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertSame('completed', BookingRequest::findOrFail($id)->status);
    }

    public function test_confirmation_arriving_before_generation_response_cannot_be_overwritten_as_pending(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/qr/generate')) {
                preg_match('/^CVS-(\d+)-(\d+)$/', $request['order_id'], $parts);
                $p = Payment::where('booking_request_id', $parts[1])->where('attempt', $parts[2])->firstOrFail();
                // The authenticated webhook has recovered the reference while generation is still returning.
                $p->update(['reference' => 'WP-RACE']);
                app(OnlineBookingService::class)->reconcile(BookingRequest::findOrFail($parts[1]), true);

                return Http::response(['reference' => 'WP-RACE', 'qr' => ['numero_referencia' => 54321, 'imagen' => 'aW1hZ2U=', 'monto' => 125.50, 'moneda' => 'BOB']], 201);
            }
            $p = Payment::where('reference', basename($request->url()))->firstOrFail();

            return Http::response(['reference' => $p->reference, 'status' => 'APROBADO', 'amount' => 125.50, 'order_id' => 'CVS-'.$p->booking_request_id.'-'.$p->attempt]);
        });
        $id = $this->startOnline();
        $this->assertSame('confirmed', Payment::where('booking_request_id', $id)->firstOrFail()->status);
        $this->assertSame('completed', BookingRequest::findOrFail($id)->status);
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_verified_failed_first_attempt_expires_at_total_deadline_without_another_charge(): void
    {
        $this->fakeGateway('RECHAZADO');
        $id = $this->startOnline();
        $this->travel(31)->seconds();
        $this->postJson('/api/online-bookings/'.$id.'/status')->assertOk()->assertJsonPath('booking.status', 'held');
        $this->travel(10)->minutes();
        $this->postJson('/api/online-bookings/'.$id.'/status')->assertOk()->assertJsonPath('booking.status', 'expired');
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_second_pending_attempt_is_not_released_using_the_first_failed_attempt(): void
    {
        $this->fakeGateway('RECHAZADO', false, false, 'PENDIENTE');
        $id = $this->startOnline();
        $this->travel(5)->minutes();
        $this->postJson('/api/online-bookings/'.$id.'/status')->assertOk()->assertJsonPath('booking.status', 'held');
        $this->postJson('/api/online-bookings/'.$id.'/retry')->assertOk()->assertJsonPath('booking.attempt', 2);
        $this->travel(5)->minutes();
        $this->postJson('/api/online-bookings/'.$id.'/status')->assertOk()->assertJsonPath('booking.status', 'processing')->assertJsonPath('payment.attempt', 2)->assertJsonPath('payment.status', 'pending');
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_followup_alternative_caused_by_clinic_preserves_exemption_at_initial_formalization(): void
    {
        $parent = $this->book();
        $parent->update(['status' => 'Atendida', 'attended_at' => now(), 'followup_suggested_at' => '2026-11-16 08:00', 'followup_note' => 'Control']);
        $data = $this->data(['date' => '2026-11-16', 'start_time' => '08:30', 'parent_appointment_id' => $parent->id, 'followup_change_cause' => 'clinic', 'payment_confirmed' => false]);
        $this->postJson('/api/appointments', $data)->assertUnprocessable();
        $data['followup_change_reason'] = 'La clínica acordó una alternativa por falta de cupo.';
        $this->postJson('/api/appointments', $data)->assertCreated()->assertJsonPath('complimentary', true)->assertJsonPath('parent_appointment_id', $parent->id)->assertJsonPath('payment_id', null);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_followup_voluntary_alternative_requires_payment_at_initial_formalization(): void
    {
        $parent = $this->book();
        $parent->update(['status' => 'Atendida', 'attended_at' => now(), 'followup_suggested_at' => '2026-11-16 08:00']);
        $this->postJson('/api/appointments', $this->data(['date' => '2026-11-16', 'start_time' => '08:30', 'parent_appointment_id' => $parent->id, 'followup_change_cause' => 'patient', 'payment_confirmed' => false]))->assertUnprocessable();
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_patient_can_discard_unpaid_request_only_after_provider_verifies_failure(): void
    {
        $this->fakeGateway('RECHAZADO');
        $id = $this->startOnline();
        $this->postJson('/api/online-bookings/'.$id.'/cancel')->assertOk()->assertJsonPath('booking.status', 'cancelled');
        $this->postJson('/api/online-bookings/'.$id.'/cancel')->assertOk()->assertJsonPath('booking.status', 'cancelled');
        $this->assertDatabaseCount('appointments', 0);
        $this->actingAs($this->patientUser)->getJson('/api/medical-availability?specialty_id='.$this->specialty->id.'&date=2026-11-09')->assertOk()->assertJsonFragment(['start_time' => '10:30']);
    }

    public function test_discarding_a_pending_or_unknown_payment_preserves_the_hold(): void
    {
        $this->fakeGateway('PENDIENTE');
        $id = $this->startOnline();
        $this->postJson('/api/online-bookings/'.$id.'/cancel')->assertUnprocessable();
        $this->assertSame('processing', BookingRequest::findOrFail($id)->status);
        Http::fake(fn () => throw new ConnectionException('Proveedor sin respuesta'));
        $this->postJson('/api/online-bookings/'.$id.'/cancel')->assertUnprocessable();
        $this->assertSame('processing', BookingRequest::findOrFail($id)->status);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_discard_cannot_cancel_a_paid_appointment_or_another_users_request(): void
    {
        $this->fakeGateway('APROBADO');
        $id = $this->startOnline();
        $this->actingAs($this->reception)->postJson('/api/online-bookings/'.$id.'/cancel')->assertForbidden();
        $this->actingAs($this->patientUser)->postJson('/api/online-bookings/'.$id.'/cancel')->assertUnprocessable();
        $this->assertSame('completed', BookingRequest::findOrFail($id)->status);
        $this->assertSame('Programada', Appointment::where('booking_request_id', $id)->firstOrFail()->status);
    }
}
