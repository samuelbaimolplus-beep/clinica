<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientAccountInvitation;
use App\Models\Specialty;
use App\Models\User;
use App\Services\Patients\RegisterPatient;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FoundationModulesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('clinica_vida_salud_test', config('database.connections.pgsql.database'));
        config(['clinic.appointments_enabled' => false]);
    }

    private function registration(array $overrides = []): array
    {
        return array_replace(['first_name' => 'Ana', 'last_name' => 'Pérez', 'identity' => 'CI-123',
            'birth_date' => '1999-03-15', 'sex' => 'Femenino', 'phone' => '70000000',
            'email' => 'ana@example.test', 'password' => 'PruebaSegura2026', 'password_confirmation' => 'PruebaSegura2026',
            'terms_accepted' => true, 'terms_version' => config('clinic.terms_version')], $overrides);
    }

    private function staff(string $role = 'Recepcionista'): User
    {
        return User::create(['name' => $role, 'email' => strtolower($role).'@example.test',
            'role' => $role, 'status' => 'Activo', 'password' => 'PruebaSegura2026']);
    }

    private function patient(array $overrides = []): Patient
    {
        return Patient::create(array_replace(['first_name' => 'Ana', 'last_name' => 'Pérez', 'identity' => 'CI-123',
            'birth_date' => '1999-03-15', 'sex' => 'Femenino', 'phone' => '70000000', 'status' => 'Activo'], $overrides));
    }

    public function test_registration_is_atomic_normalized_and_cannot_assign_privileges(): void
    {
        $response = $this->postJson('/api/register', $this->registration(['email' => '  ANA@EXAMPLE.TEST  ',
            'identity' => ' ci-123 ', 'role' => 'Administrador', 'status' => 'Inactivo', 'patient_id' => 999]));
        $response->assertCreated()->assertJsonPath('user.role', 'Paciente')->assertJsonPath('user.status', 'Activo')
            ->assertJsonMissingPath('user.password');
        $user = User::findOrFail($response->json('user.id'));
        $this->assertGuest();
        $this->getJson('/api/references')->assertUnauthorized();
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'PruebaSegura2026'])->assertOk();
        $this->assertTrue(Hash::check('PruebaSegura2026', $user->password));
        $this->assertSame('ana@example.test', $user->email);
        $this->assertSame('CI-123', $user->patient->identity);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertSame(config('clinic.terms_version'), $user->terms_version);
        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('audit_events', ['action' => 'registered_online', 'user_id' => $user->id, 'record_id' => $user->patient_id]);
    }

    public function test_terms_confirmation_and_duplicate_identity_prevent_partial_registration(): void
    {
        $this->postJson('/api/register', $this->registration(['terms_accepted' => false]))->assertUnprocessable()->assertJsonValidationErrors('terms_accepted');
        $this->postJson('/api/register', $this->registration(['terms_version' => 'obsolete']))->assertUnprocessable()->assertJsonValidationErrors('terms_version');
        $this->postJson('/api/register', $this->registration(['password_confirmation' => 'OtraClave']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('patients', 0);
        $this->patient();
        $this->postJson('/api/register', $this->registration(['identity' => ' ci-123 ']))->assertUnprocessable()->assertJsonValidationErrors('identity');
        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_patient_cannot_download_catalogs_or_use_undocumented_contact_updates(): void
    {
        $other = $this->patient(['identity' => 'OTHER', 'first_name' => 'Otra']);
        $response = $this->postJson('/api/register', $this->registration())->assertCreated();
        $id = $response->json('user.patient_id');
        User::findOrFail($response->json('user.id'))->forceFill(['email_verified_at' => now()])->save();
        $this->postJson('/api/login', ['email' => 'ana@example.test', 'password' => 'PruebaSegura2026'])->assertOk();
        $this->getJson('/api/profile?patient_id='.$other->id)->assertNotFound();
        $this->getJson('/api/references')->assertExactJson(['doctors' => [], 'patients' => [], 'specialties' => []]);
        foreach (['users', 'doctors', 'specialties', 'patients'] as $module) {
            $this->getJson('/api/entities/'.$module)->assertForbidden();
            $this->postJson('/api/entities/'.$module, [])->assertForbidden();
        }
        $this->getJson('/api/schedules?doctor_id=1')->assertForbidden();
        $this->putJson('/api/profile', ['phone' => '79999999', 'address' => 'Calle Prueba',
            'patient_id' => $other->id, 'identity' => 'HACK', 'status' => 'Inactivo'])
            ->assertNotFound();
        $this->assertSame('70000000', $other->fresh()->phone);
        $this->assertSame('Activo', Patient::findOrFail($id)->status);
    }

    public function test_database_failure_rolls_back_patient_account_and_audit(): void
    {
        $existing = $this->staff();
        $auditCount = AuditEvent::count();
        try {
            app(RegisterPatient::class)->handle($this->registration(['email' => $existing->email]));
            $this->fail('La unicidad del correo debió impedir la cuenta.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->errorInfo[0]);
        }
        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($auditCount, AuditEvent::count());
    }

    public function test_database_constraints_prevent_duplicate_identity_and_exact_undocumented_patients(): void
    {
        $this->patient();
        $this->patient(['identity' => null, 'first_name' => 'Beatriz']);
        foreach ([['identity' => ' ci-123 '], ['identity' => null, 'first_name' => 'beatriz']] as $attributes) {
            try {
                DB::transaction(fn () => $this->patient($attributes));
                $this->fail('La base de datos permitió un paciente duplicado.');
            } catch (QueryException $exception) {
                $this->assertSame('23505', $exception->errorInfo[0]);
            }
        }
        $this->assertDatabaseCount('patients', 2);
    }

    public function test_patient_deactivation_revokes_session_and_login(): void
    {
        $response = $this->postJson('/api/register', $this->registration())->assertCreated();
        User::findOrFail($response->json('user.id'))->forceFill(['email_verified_at' => now()])->save();
        $this->postJson('/api/login', ['email' => 'ana@example.test', 'password' => 'PruebaSegura2026'])->assertOk();
        Patient::findOrFail($response->json('user.patient_id'))->update(['status' => 'Inactivo']);
        $this->getJson('/api/references')->assertUnauthorized();
        $this->postJson('/api/login', ['email' => 'ana@example.test', 'password' => 'PruebaSegura2026'])->assertUnprocessable();
        $this->getJson('/api/session')->assertJsonPath('user', null);
    }

    public function test_only_reception_can_issue_verified_link_and_it_preserves_patient_id(): void
    {
        $patient = $this->patient();
        $reception = $this->staff();
        $this->actingAs($this->staff('Administrador'))->postJson('/api/patients/'.$patient->id.'/account-invitation', ['identity_verified' => true])->assertForbidden();
        $this->actingAs($reception)->postJson('/api/patients/'.$patient->id.'/account-invitation', [])->assertUnprocessable();
        $issued = $this->postJson('/api/patients/'.$patient->id.'/account-invitation', ['identity_verified' => true])->assertCreated();
        $token = $issued->json('activation_code');
        $this->assertNotSame($token, PatientAccountInvitation::firstOrFail()->getRawOriginal('token_hash'));
        $this->postJson('/api/logout')->assertOk();
        $this->postJson('/api/register', $this->registration(['activation_code' => $token]))->assertCreated()->assertJsonPath('user.patient_id', $patient->id);
        $this->assertDatabaseCount('patients', 1);
        $this->assertNotNull(PatientAccountInvitation::firstOrFail()->consumed_at);
        $this->assertGuest();
        $this->postJson('/api/register', $this->registration(['email' => 'second@example.test', 'activation_code' => $token]))->assertUnprocessable();
        $this->assertSame(1, User::where('role', 'Paciente')->count());
        $this->assertStringNotContainsString($token, AuditEvent::all()->toJson());
    }

    public function test_link_rejects_wrong_identity_expired_and_revoked_codes(): void
    {
        $patient = $this->patient();
        $reception = $this->staff();
        $first = $this->actingAs($reception)->postJson('/api/patients/'.$patient->id.'/account-invitation', ['identity_verified' => true])->assertCreated()->json('activation_code');
        $second = $this->postJson('/api/patients/'.$patient->id.'/account-invitation', ['identity_verified' => true])->assertCreated()->json('activation_code');
        $this->postJson('/api/logout');
        $this->postJson('/api/register', $this->registration(['activation_code' => $first]))->assertUnprocessable();
        $this->postJson('/api/register', $this->registration(['activation_code' => $second, 'first_name' => 'Otra']))->assertUnprocessable();
        $this->assertNull(PatientAccountInvitation::where('token_hash', hash('sha256', $second))->firstOrFail()->consumed_at);
        $this->travel(49)->hours();
        $this->postJson('/api/register', $this->registration(['activation_code' => $second]))->assertUnprocessable();
        $this->assertDatabaseCount('patients', 1);
        $this->assertSame(0, User::where('role', 'Paciente')->count());
    }

    public function test_audit_tracks_authentication_and_catalog_changes_without_secrets(): void
    {
        $admin = $this->staff('Administrador');
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'Incorrecta'])->assertUnprocessable();
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'PruebaSegura2026'])->assertOk();
        $specialty = $this->postJson('/api/entities/specialties', ['name' => 'Cardiología', 'status' => 'Activo'])->assertCreated()->json('id');
        $this->putJson('/api/entities/specialties/'.$specialty, ['name' => 'Cardiología', 'status' => 'Inactivo'])->assertOk();
        $this->postJson('/api/logout')->assertOk();
        foreach ([['auth', 'login', 'failure'], ['auth', 'login', 'success'], ['auth', 'logout', 'success'], ['specialties', 'updated', 'success']] as [$module, $action, $result]) {
            $this->assertDatabaseHas('audit_events', compact('module', 'action', 'result'));
        }
        $json = AuditEvent::all()->toJson();
        foreach (['PruebaSegura2026', 'Incorrecta', $admin->password, 'remember_token', 'password'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertDatabaseHas('audit_events', ['record_id' => $specialty, 'module' => 'specialties', 'user_id' => $admin->id]);
    }

    public function test_audit_database_rejects_update_delete_and_truncate(): void
    {
        $this->staff();
        $event = AuditEvent::firstOrFail();
        foreach (['UPDATE audit_events SET result = \'failure\'', 'DELETE FROM audit_events', 'TRUNCATE audit_events'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail('PostgreSQL permitió modificar la bitácora.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('inmutable', $exception->getMessage());
            }
        }
        $this->assertSame('success', $event->fresh()->result);
    }

    public function test_transaction_rolls_back_audit_together_with_business_changes(): void
    {
        $before = AuditEvent::count();
        try {
            DB::transaction(function () {
                Specialty::create(['name' => 'Rollback', 'status' => 'Activo']);
                throw new \RuntimeException('rollback esperado');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback esperado', $exception->getMessage());
        }
        $this->assertDatabaseMissing('specialties', ['name' => 'Rollback']);
        $this->assertSame($before, AuditEvent::count());
    }

    public function test_patients_without_identity_are_supported_and_exact_duplicates_rejected(): void
    {
        $this->actingAs($this->staff());
        $data = array_intersect_key($this->registration(['identity' => null]), array_flip(['identity', 'first_name', 'last_name', 'birth_date', 'sex', 'phone', 'email'])) + ['status' => 'Activo'];
        $id = $this->postJson('/api/entities/patients', $data)->assertCreated()->json('id');
        $this->postJson('/api/entities/patients', $data)->assertUnprocessable();
        $this->postJson('/api/entities/patients', array_replace($data, ['first_name' => 'Beatriz']))->assertCreated();
        $this->getJson('/api/entities/patients?search=PÉREZ')->assertOk()->assertJsonCount(2);
        $this->getJson('/api/entities/patients?search=%25')->assertOk()->assertJsonCount(0);
        $this->putJson('/api/entities/patients/'.$id, array_replace($data, ['status' => 'Inactivo']))->assertOk();
        $this->assertDatabaseHas('patients', ['id' => $id, 'status' => 'Inactivo']);
    }

    public function test_deferred_modules_are_disabled_in_api_and_menu(): void
    {
        $this->actingAs($this->staff());
        $this->postJson('/api/appointments', [])->assertNotFound();
        $this->getJson('/api/availability')->assertNotFound();
        $this->getJson('/api/reports')->assertNotFound();
        $this->getJson('/api/session')->assertJsonPath('access.Recepcionista', ['CU01', 'CU02', 'CU06', 'CU24', 'CU25', 'CU08', 'CU17']);
        $this->get('/terms')->assertOk()->assertSee(config('clinic.terms_version'));
    }

    public function test_inactive_doctor_cannot_log_in_even_when_account_is_active(): void
    {
        $doctor = Doctor::create(['registration' => 'DOC-1', 'first_name' => 'Médico', 'last_name' => 'Prueba', 'email' => 'doctor@example.test', 'phone' => '70000000', 'status' => 'Inactivo']);
        $user = User::create(['name' => 'Médico', 'email' => 'medico@example.test', 'password' => 'PruebaSegura2026', 'role' => 'Médico', 'doctor_id' => $doctor->id, 'status' => 'Activo']);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'PruebaSegura2026'])->assertUnprocessable();
        $this->assertGuest();
    }
}
