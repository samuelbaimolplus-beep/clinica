<?php

namespace Tests\Feature;

use App\Models\AgendaAdjustment;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\BookingRequest;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\Specialty;
use App\Models\User;
use App\Paquetes\P07_ReservasPagosYSeguimientos\Compartido\Integraciones\PayPalGateway;
use App\Paquetes\P07_ReservasPagosYSeguimientos\CU18_ReservarCitaOnline\Servicios\OnlineBookingService;
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
        config(['clinic.appointments_enabled' => true, 'paypal.enabled' => true, 'paypal.client_id' => 'client-test', 'paypal.secret' => 'secret-test', 'paypal.bob_per_usd' => '10']);
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-11-09 07:00', 'America/La_Paz'));
        $this->specialty = Specialty::create(['name' => 'Especialidad pagos', 'status' => 'Activo', 'fee_minor' => 12550]);
        $this->doctor = Doctor::create(['registration' => 'PAY-01', 'first_name' => 'Ana', 'last_name' => 'Médica', 'email' => 'doctor-pay@test.local', 'phone' => '70000000', 'status' => 'Activo']);
        $this->doctor->specialties()->attach($this->specialty);
        Schedule::create(['doctor_id' => $this->doctor->id, 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '12:00', 'duration' => 30]);
        $this->patient = Patient::create(['identity' => 'PAY-001', 'first_name' => 'María', 'last_name' => 'Paciente', 'phone' => '70000001', 'birth_date' => '2000-01-01', 'sex' => 'Femenino', 'status' => 'Activo']);
        foreach (['reception' => 'Recepcionista', 'admin' => 'Administrador', 'patientUser' => 'Paciente', 'physician' => 'Médico'] as $key => $role) {
            $this->$key = $this->verifiedAccount(['name' => $key, 'email' => $key.'@payments.test', 'password' => 'PruebaSegura2026', 'role' => $role, 'status' => 'Activo',
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

    public function test_physician_appointment_responses_exclude_patient_contact_and_payment_data(): void
    {
        $appointment = $this->book();
        $this->patient->update(['identity' => 'PRIVATE-CI', 'email' => 'private@patient.test', 'address' => 'Private address']);

        $agenda = $this->actingAs($this->physician)->getJson('/api/appointments?mode=agenda')->assertOk()->assertJsonCount(1);
        $agenda->assertJsonPath('0.id', $appointment->id)
            ->assertJsonPath('0.patient.first_name', 'María')
            ->assertJsonMissingPath('0.patient.identity')
            ->assertJsonMissingPath('0.patient.phone')
            ->assertJsonMissingPath('0.patient.email')
            ->assertJsonMissingPath('0.patient.address')
            ->assertJsonMissingPath('0.payment')
            ->assertJsonMissingPath('0.payment_id')
            ->assertJsonMissingPath('0.booking_request_id')
            ->assertJsonMissingPath('0.created_by')
            ->assertJsonMissingPath('0.requires_arrival_payment');

        $this->actingAs($this->reception)->postJson('/api/appointments/'.$appointment->id.'/attendance')->assertOk();
        $consultation = $this->actingAs($this->physician)
            ->postJson('/api/appointments/'.$appointment->id.'/attend', ['consultation_finished' => true])->assertOk();
        $consultation->assertJsonMissingPath('payment')
            ->assertJsonMissingPath('payment_id')
            ->assertJsonMissingPath('requires_arrival_payment')
            ->assertJsonMissingPath('patient.phone')
            ->assertJsonMissingPath('patient.address');
    }

    public function test_refund_list_returns_only_fields_needed_for_the_refund_workflow(): void
    {
        $appointment = $this->book();
        $this->actingAs($this->reception)->postJson('/api/appointments/'.$appointment->id.'/cancel', [
            'reason' => 'Indisponibilidad de la clínica', 'cause' => 'clinic',
        ])->assertOk();
        $this->actingAs($this->patientUser)->postJson('/api/appointments/'.$appointment->id.'/refund', [
            'reason' => 'La clínica no pudo atender la cita',
        ])->assertCreated();

        $response = $this->getJson('/api/refunds')->assertOk()->assertJsonCount(1);
        $response->assertJsonPath('0.appointment_id', $appointment->id)
            ->assertJsonMissingPath('0.appointment')
            ->assertJsonMissingPath('0.payment')
            ->assertJsonMissingPath('0.payment_id')
            ->assertJsonMissingPath('0.created_by')
            ->assertJsonMissingPath('0.resolved_by');
    }

    private function paypalOrder(Payment $payment, string $state = 'APPROVED', ?string $captureState = null, bool $wrongAmount = false): array
    {
        $gateway = app(PayPalGateway::class);
        $ref = $gateway->reference($payment);
        $id = 'PP-'.$ref;
        $amount = ['currency_code' => 'USD', 'value' => $wrongAmount ? '0.01' : $gateway->usdValue($payment)];
        $unit = ['reference_id' => $ref, 'custom_id' => $ref, 'invoice_id' => $ref, 'amount' => $amount];
        if ($captureState) {
            $unit['payments']['captures'] = [['id' => 'CAP-'.$payment->id, 'status' => $captureState, 'amount' => $amount, 'final_capture' => true]];
        }

        return ['id' => $id, 'intent' => 'CAPTURE', 'status' => $state, 'purchase_units' => [$unit],
            'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token='.$id]]];
    }

    private function paypalHeaders(): array
    {
        return ['PAYPAL-AUTH-ALGO' => 'SHA256withRSA', 'PAYPAL-CERT-URL' => 'https://api-m.sandbox.paypal.com/cert/test',
            'PAYPAL-TRANSMISSION-ID' => 'test-event', 'PAYPAL-TRANSMISSION-SIG' => 'test-signature', 'PAYPAL-TRANSMISSION-TIME' => now()->toIso8601String()];
    }

    private function fakeGateway(string $state = 'APROBADO', bool $wrongAmount = false, bool $loseGenerationResponse = false, ?string $secondState = null, bool $race = false): void
    {
        Http::fake(function ($request) use ($state, $wrongAmount, $loseGenerationResponse, $secondState, $race) {
            if (str_ends_with($request->url(), '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'test-token']);
            }
            if (str_ends_with($request->url(), '/verify-webhook-signature')) {
                return Http::response(['verification_status' => 'SUCCESS']);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/v2/checkout/orders')) {
                preg_match('/^CVS-(\d+)-(\d+)$/', $request['purchase_units'][0]['custom_id'], $m);
                $payment = Payment::where('booking_request_id', $m[1])->where('attempt', $m[2])->firstOrFail();
                if ($loseGenerationResponse) {
                    throw new ConnectionException('Tiempo de espera de prueba');
                }
                $created = $this->paypalOrder($payment, 'CREATED');
                if ($race) {
                    $payment->update(['reference' => $created['id']]);
                    app(OnlineBookingService::class)->reconcile(BookingRequest::findOrFail($payment->booking_request_id), true);
                }

                return Http::response($created, 201);
            }
            $id = str_ends_with($request->url(), '/capture') ? basename(dirname($request->url())) : basename($request->url());
            preg_match('/^PP-CVS-(\d+)-(\d+)$/', $id, $m);
            $payment = Payment::where('booking_request_id', $m[1])->where('attempt', $m[2])->firstOrFail();
            $current = $payment->attempt === 2 ? ($secondState ?? $state) : $state;
            $capture = match ($current) {
                'PENDIENTE' => 'PENDING', 'RECHAZADO' => 'DECLINED', default => null
            };
            if (str_ends_with($request->url(), '/capture')) {
                $capture = 'COMPLETED';
            }

            return Http::response($this->paypalOrder($payment, $capture ? 'COMPLETED' : 'APPROVED', $capture, $wrongAmount));
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

    public function test_demand_block_is_listed_and_visible_only_for_compatible_presential_booking(): void
    {
        $data = ['kind' => 'presential_block', 'specialty_id' => $this->specialty->id, 'quantity' => 1,
            'blocks' => [['doctor_id' => $this->doctor->id, 'date' => '2026-11-09', 'start_time' => '08:00', 'end_time' => '08:30']]];
        $id = $this->postJson('/api/agenda-adjustments', $data)->assertCreated()->json('0.id');
        $created = AuditEvent::where('record_type', 'AgendaAdjustment')->where('record_id', $id)->where('action', 'created')->firstOrFail();
        $this->assertSame('active', $created->details['record']['status']);
        $this->assertSame('08:00:00', $created->details['record']['start_time']);
        $this->getJson('/api/agenda-adjustments?date=2026-11-09&specialty_id='.$this->specialty->id)
            ->assertOk()->assertJsonPath('0.id', $id)->assertJsonPath('0.status', 'active');
        $query = '/api/medical-availability?date=2026-11-09&specialty_id='.$this->specialty->id;
        $this->getJson($query)->assertOk()->assertJsonMissing(['start_time' => '08:00']);
        $this->getJson($query.'&include_demand=1')->assertOk()
            ->assertJsonPath('blocks.0.start_time', '08:00')->assertJsonPath('blocks.0.demand_blocked', true);

        $other = Specialty::create(['name' => 'Otra especialidad de bloqueo', 'status' => 'Activo', 'fee_minor' => 10000]);
        $this->doctor->specialties()->attach($other);
        $this->getJson('/api/medical-availability?date=2026-11-09&specialty_id='.$other->id.'&include_demand=1')
            ->assertOk()->assertJsonMissing(['start_time' => '08:00']);
        $this->actingAs($this->patientUser)->getJson($query.'&include_demand=1')
            ->assertOk()->assertJsonMissing(['start_time' => '08:00']);

        $this->actingAs($this->reception)->postJson('/api/agenda-adjustments/release', ['ids' => [$id]])->assertOk();
        $this->getJson($query.'&include_demand=1')->assertOk()
            ->assertJsonPath('blocks.0.start_time', '08:00')->assertJsonPath('blocks.0.demand_blocked', false);
        $this->assertDatabaseHas('agenda_adjustments', ['id' => $id, 'status' => 'released']);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('payments', 0);
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
        config(['paypal.enabled' => false]);
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
        $event = AuditEvent::where('action', 'rescheduled')->where('record_id', $a->id)->latest('id')->firstOrFail();
        $this->assertSame('Acepta por WhatsApp', $event->details['reason']);
        $this->assertSame('08:00:00', $event->details['changes']['start_time']['before']);
        $this->assertSame('09:00', $event->details['changes']['start_time']['after']);
        $this->assertTrue($event->details['changes']['patient_accepted']['after']);
        $this->assertSame('patient', $event->details['changes']['change_cause']['after']);
        $this->assertSame('María Paciente', $event->details['related']['patient_id']['label']);
        $this->assertSame($this->reception->id, $event->user_id);
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

    public function test_paypal_webhook_requires_signature_and_backend_confirmation_and_is_idempotent(): void
    {
        config(['paypal.webhook_id' => 'WH-TEST']);
        $this->fakeGateway();
        $id = $this->startOnline();
        $payment = Payment::where('booking_request_id', $id)->firstOrFail();
        $payload = ['id' => 'EV-TEST', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => $payment->reference]];
        $this->postJson('/pagos/paypal/webhook', $payload)->assertUnauthorized();
        $this->withHeaders($this->paypalHeaders())->postJson('/pagos/paypal/webhook', array_replace($payload, ['resource' => ['other' => 'missing-order-id']]))->assertUnprocessable();
        $this->postJson('/pagos/paypal/webhook', $payload)->assertOk();
        $this->postJson('/pagos/paypal/webhook', $payload)->assertOk();
        $this->assertDatabaseCount('appointments', 1);
        $this->assertSame('completed', BookingRequest::findOrFail($id)->status);
    }

    public function test_paypal_webhook_without_configuration_rejects_events_without_contacting_paypal(): void
    {
        config(['paypal.webhook_id' => null]);
        Http::fake();
        $this->postJson('/pagos/paypal/webhook', ['id' => 'EV-NO-CONFIG', 'event_type' => 'CHECKOUT.ORDER.APPROVED',
            'resource' => ['id' => 'NO-ORDER']])->assertStatus(503);
        Http::assertNothingSent();
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_paypal_webhook_acknowledges_a_signed_simulator_event_with_a_nonexistent_order(): void
    {
        config(['paypal.webhook_id' => 'WH-TEST']);
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'test-token']),
            '*/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
            '*/v2/checkout/orders/SIMULATED-ORDER' => Http::response(['name' => 'RESOURCE_NOT_FOUND'], 404),
        ]);
        $this->withHeaders($this->paypalHeaders())->postJson('/pagos/paypal/webhook', ['id' => 'EV-SIMULATOR',
            'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'SIMULATED-ORDER']])
            ->assertOk()->assertJsonPath('received', true)->assertJsonPath('processed', false)->assertJsonPath('reason', 'order_not_found');
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('payments', 0);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/capture'));
    }

    public function test_paypal_webhook_keeps_transient_provider_errors_retryable(): void
    {
        config(['paypal.webhook_id' => 'WH-TEST']);
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'test-token']),
            '*/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
            '*/v2/checkout/orders/UNAVAILABLE-ORDER' => Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 500),
        ]);
        $this->withHeaders($this->paypalHeaders())->postJson('/pagos/paypal/webhook', ['id' => 'EV-TEMPORARY-ERROR',
            'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'UNAVAILABLE-ORDER']])->assertStatus(500);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_paypal_webhook_rejects_a_signature_declared_invalid_by_paypal(): void
    {
        config(['paypal.webhook_id' => 'WH-TEST']);
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'test-token']),
            '*/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE']),
        ]);
        $payload = ['id' => 'EV-BAD-SIGNATURE', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'NO-ORDER']];
        $this->withHeaders($this->paypalHeaders())->postJson('/pagos/paypal/webhook', $payload)->assertUnauthorized();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/verify-webhook-signature')
            && $request['webhook_id'] === 'WH-TEST' && $request['webhook_event'] === $payload
            && $request['transmission_sig'] === 'test-signature');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v2/checkout/orders'));
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_paypal_webhook_does_not_trust_completed_event_if_remote_capture_is_pending(): void
    {
        config(['paypal.webhook_id' => 'WH-TEST']);
        $this->fakeGateway('PENDIENTE');
        $id = $this->startOnline();
        $payment = Payment::where('booking_request_id', $id)->firstOrFail();
        $this->withHeaders($this->paypalHeaders())->postJson('/pagos/paypal/webhook', [
            'id' => 'EV-CAPTURE-PENDING', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => ['id' => 'CAP-TEST', 'status' => 'COMPLETED', 'supplementary_data' => ['related_ids' => ['order_id' => $payment->reference]]],
        ])->assertOk();
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('processing', BookingRequest::findOrFail($id)->status);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_paypal_capture_webhook_confirms_once_after_backend_verification(): void
    {
        config(['paypal.webhook_id' => 'WH-TEST']);
        $this->fakeGateway();
        $id = $this->startOnline();
        $payment = Payment::where('booking_request_id', $id)->firstOrFail();
        $payload = ['id' => 'EV-CAPTURE-COMPLETE', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => ['id' => 'CAP-TEST', 'supplementary_data' => ['related_ids' => ['order_id' => $payment->reference]]]];
        $this->withHeaders($this->paypalHeaders())->postJson('/pagos/paypal/webhook', $payload)->assertOk();
        $this->postJson('/pagos/paypal/webhook', $payload)->assertOk();
        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->capture_id);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertSame('completed', BookingRequest::findOrFail($id)->status);
        $captures = Http::recorded(fn ($request) => str_ends_with($request->url(), '/capture'));
        $this->assertCount(1, $captures);
    }

    public function test_paypal_webhook_rejects_a_remote_order_with_the_wrong_amount(): void
    {
        config(['paypal.webhook_id' => 'WH-TEST']);
        $this->fakeGateway('APROBADO', true);
        $id = $this->startOnline();
        $payment = Payment::where('booking_request_id', $id)->firstOrFail();
        $this->withHeaders($this->paypalHeaders())->postJson('/pagos/paypal/webhook', ['id' => 'EV-WRONG-AMOUNT',
            'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => $payment->reference]])->assertUnprocessable();
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertDatabaseCount('appointments', 0);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/capture'));
    }

    public function test_paypal_webhook_acknowledges_unsupported_signed_events_without_changing_payments(): void
    {
        config(['paypal.webhook_id' => 'WH-TEST']);
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'test-token']),
            '*/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        ]);
        $this->withHeaders($this->paypalHeaders())->postJson('/pagos/paypal/webhook', ['id' => 'EV-UNSUPPORTED',
            'event_type' => 'UNSUPPORTED.EVENT', 'resource' => ['id' => 'NO-ORDER']])->assertOk()->assertJsonPath('received', true);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v2/checkout/orders'));
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('payments', 0);
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

    public function test_authenticated_webhook_recovers_a_lost_creation_response_without_charging_again(): void
    {
        config(['paypal.webhook_id' => 'WH-TEST']);
        $this->fakeGateway('APROBADO', false, true);
        $id = $this->startOnline();
        $payment = Payment::where('booking_request_id', $id)->firstOrFail();
        $this->assertSame('unknown', $payment->status);
        $this->assertNull($payment->reference);
        $this->postJson('/api/online-bookings/'.$id.'/retry')->assertUnprocessable();
        $this->withHeaders($this->paypalHeaders())->postJson('/pagos/paypal/webhook', ['id' => 'EV-RECOVER', 'event_type' => 'CHECKOUT.ORDER.APPROVED',
            'resource' => ['id' => 'PP-CVS-'.$id.'-1']])->assertOk();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertSame('completed', BookingRequest::findOrFail($id)->status);
    }

    public function test_confirmation_arriving_before_creation_response_cannot_be_overwritten_as_pending(): void
    {
        $this->fakeGateway('APROBADO', false, false, null, true);
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
