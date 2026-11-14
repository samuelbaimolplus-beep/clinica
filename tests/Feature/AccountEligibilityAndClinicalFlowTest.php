<?php

namespace Tests\Feature;

use App\Models\Especialidad;
use App\Models\Horario;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Usuario;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\RequisitosCuenta;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountEligibilityAndClinicalFlowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('clinica_vida_salud_test', config('database.connections.pgsql.database'));
        $this->travelTo(Carbon::parse('2026-11-09 07:00:00', 'America/La_Paz'));
        config(['clinic.appointments_enabled' => true]);
    }

    private function staff(string $role = 'Recepcionista', ?int $doctor = null): Usuario
    {
        return $this->verifiedAccount(['name' => 'Personal '.Str::random(6), 'email' => Str::random(10).'@flow.test',
            'password' => 'ClaveDePrueba2026', 'role' => $role, 'status' => 'Activo', 'doctor_id' => $doctor]);
    }

    private function patient(string $birth = '2000-01-01'): Paciente
    {
        return Paciente::create(['first_name' => 'Paciente', 'last_name' => Str::random(8), 'birth_date' => $birth,
            'sex' => 'Femenino', 'phone' => '70000000', 'status' => 'Activo']);
    }

    private function consent(): array
    {
        return ['email' => Str::random(12).'@consent.test', 'username' => 'portal.'.strtolower(Str::random(12)),
            'password' => 'ClaveDePrueba2026', 'password_confirmation' => 'ClaveDePrueba2026',
            'identity_verified' => true, 'terms_accepted' => true, 'terms_version' => config('clinic.terms_version')];
    }

    public function test_age_boundary_is_eighteen_completed_years_not_a_birth_year_approximation(): void
    {
        $rule = app(RequisitosCuenta::class);
        $this->assertTrue($rule->esMayorDeEdad('2008-11-09'));
        foreach (['2008-11-10', '2020-01-01', '2027-01-01', '2000-02-31', null] as $date) {
            $this->assertFalse($rule->esMayorDeEdad($date));
        }
        $this->travelTo(Carbon::parse('2028-02-29 07:00:00', 'America/La_Paz'));
        $this->assertTrue($rule->esMayorDeEdad('2010-02-28'));
        $this->assertFalse($rule->esMayorDeEdad('2010-03-01'));
    }

    public function test_all_staff_roles_require_an_adult_birth_date_even_with_a_forged_activation_flag(): void
    {
        $this->actingAs($this->staff('Superadmin'));
        foreach (['Superadmin', 'Administrador', 'Recepcionista'] as $role) {
            $data = ['name' => 'Personal nuevo', 'email' => Str::random(10).'@adult.test', 'password' => 'ClaveDePrueba2026',
                'role' => $role, 'status' => 'Activo', 'email_verified_at' => now()->toISOString()];
            $this->postJson('/api/entities/users', $data)->assertUnprocessable()->assertJsonValidationErrors('birth_date');
            $this->postJson('/api/entities/users', $data + ['birth_date' => '2008-11-10'])->assertUnprocessable()->assertJsonValidationErrors('birth_date');
            $this->postJson('/api/entities/users', $data + ['birth_date' => '2008-11-09'])->assertCreated()->assertJsonPath('email_verified_at', null);
        }
    }

    public function test_a_child_has_an_ordinary_patient_record_but_cannot_get_a_portal_account(): void
    {
        $this->actingAs($this->staff());
        $response = $this->postJson('/api/entities/patients', ['first_name' => 'Niña', 'last_name' => 'Prueba',
            'birth_date' => '2018-01-01', 'sex' => 'Femenino', 'phone' => '70000111'])->assertCreated();
        $id = $response->json('id');
        $this->postJson('/api/patients/'.$id.'/account', $this->consent() + ['birth_date' => '1990-01-01', 'role' => 'Administrador'])
            ->assertUnprocessable()->assertJsonValidationErrors('birth_date');
        $this->assertTrue(Paciente::whereKey($id)->exists());
        $this->assertFalse(Usuario::where('patient_id', $id)->exists());
    }

    public function test_portal_is_optional_and_requires_explicit_current_consent(): void
    {
        $patient = $this->patient();
        $this->actingAs($this->staff());
        $data = $this->consent();
        $this->postJson('/api/patients/'.$patient->id.'/account', array_diff_key($data, ['terms_accepted' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('terms_accepted');
        $this->postJson('/api/patients/'.$patient->id.'/account', array_replace($data, ['terms_version' => 'antigua']))
            ->assertUnprocessable()->assertJsonValidationErrors('terms_version');
        $this->assertFalse(Usuario::where('patient_id', $patient->id)->exists());
        $r = $this->postJson('/api/patients/'.$patient->id.'/account', $data)->assertCreated()->assertJsonPath('user.email_verified_at', null);
        $account = Usuario::findOrFail($r->json('user.id'));
        $this->assertNotNull($account->terms_accepted_at);
        $this->assertSame(config('clinic.terms_version'), $account->terms_version);
    }

    public function test_existing_staff_with_unknown_birth_date_must_complete_verification_without_fabricated_data(): void
    {
        $user = $this->staff('Administrador');
        DB::table('users')->where('id', $user->id)->update(['birth_date' => null]);
        $login = ['email' => $user->email, 'password' => 'ClaveDePrueba2026'];
        $this->postJson('/api/login', $login)->assertForbidden()->assertJsonPath('eligibility_required', true);
        $this->assertGuest();
        $this->postJson('/api/login', $login + ['birth_date' => '2015-01-01'])->assertUnprocessable();
        $this->postJson('/api/login', $login + ['birth_date' => '1990-01-01'])->assertOk();
        $this->assertSame('1990-01-01', $user->fresh()->birth_date);
    }

    public function test_a_patient_corrected_to_underage_loses_personal_access_but_keeps_the_record(): void
    {
        $patient = $this->patient();
        $user = $this->verifiedAccount(['name' => 'Paciente adulto', 'email' => 'age-session@flow.test', 'password' => 'ClaveDePrueba2026', 'role' => 'Paciente', 'patient_id' => $patient->id]);
        $this->actingAs($user)->getJson('/api/workspace')->assertOk();
        $patient->update(['birth_date' => '2018-01-01']);
        $this->getJson('/api/workspace')->assertUnauthorized();
        $this->assertGuest();
        $this->assertTrue(Paciente::whereKey($patient->id)->exists());
    }

    public function test_full_clinic_flow_for_a_child_without_email_or_portal_account(): void
    {
        $specialty = Especialidad::create(['name' => 'Atención infantil flujo', 'status' => 'Activo', 'fee_minor' => 10000]);
        $doctor = Medico::create(['registration' => 'FLOW-DOCTOR', 'first_name' => 'Andrea', 'last_name' => 'Flujo', 'email' => 'doctor@flow.test', 'phone' => '70000000', 'status' => 'Activo']);
        $doctor->specialties()->attach($specialty->id);
        foreach ([1, 2] as $weekday) {
            Horario::create(['doctor_id' => $doctor->id, 'weekday' => $weekday, 'start_time' => '08:00', 'end_time' => '14:00', 'duration' => 30]);
        }
        $patient = $this->patient('2018-01-01');
        $reception = $this->staff();
        $physician = $this->staff('Médico', $doctor->id);
        $this->actingAs($reception);
        $book = $this->postJson('/api/appointments', ['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'specialty_id' => $specialty->id,
            'date' => '2026-11-09', 'start_time' => '08:00', 'idempotency_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'payment_amount_minor' => 10000, 'payment_confirmed' => true])->assertCreated();
        $id = $book->json('id');
        $this->postJson('/api/appointments/'.$id.'/attendance')->assertOk()->assertJsonPath('status', 'Confirmada');
        $this->actingAs($physician)->getJson('/api/appointments?mode=attend')->assertOk()->assertJsonPath('0.care_ready', true)
            ->assertJsonMissingPath('0.patient.birth_date')->assertJsonMissingPath('0.payment');
        $this->postJson('/api/appointments/'.$id.'/attend', ['consultation_finished' => true, 'followup_suggested_at' => '2026-11-10T08:00', 'followup_note' => 'Control de demostración'])
            ->assertOk()->assertJsonPath('status', 'Atendida');
        $this->actingAs($reception)->postJson('/api/appointments', ['parent_appointment_id' => $id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'specialty_id' => $specialty->id,
            'date' => '2026-11-10', 'start_time' => '08:00', 'idempotency_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('complimentary', true);
        $this->assertFalse(Usuario::where('patient_id', $patient->id)->exists());
        $this->assertNull($patient->fresh()->email);
    }

    public function test_workspace_and_history_do_not_expose_other_patients_or_cache_sensitive_content(): void
    {
        $patient = $this->patient();
        $other = $this->patient();
        $user = $this->verifiedAccount(['name' => 'Mi cuenta', 'email' => 'scope@flow.test', 'password' => 'ClaveDePrueba2026', 'role' => 'Paciente', 'patient_id' => $patient->id]);
        $this->actingAs($user)->getJson('/api/workspace')->assertOk()->assertJsonPath('next', []);
        $this->assertStringContainsString('no-store', $this->getJson('/api/workspace')->headers->get('Cache-Control'));
        $denied = $this->getJson('/api/appointments?mode=history&patient_id='.$other->id)->assertForbidden();
        $this->assertStringContainsString('no-store', $denied->headers->get('Cache-Control'));
    }
}
