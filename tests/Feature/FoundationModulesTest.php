<?php

namespace Tests\Feature;

use App\Models\Especialidad;
use App\Models\EventoAuditoria;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Usuario;
use App\Notifications\VerificarCorreoClinica;
use App\Paquetes\P01_UsuariosYAcceso\CU23_RegistrarCuentaDePaciente\Servicios\CrearCuentaPaciente;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
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
            'username' => 'ana.paciente', 'identity_verified' => true, 'terms_accepted' => true, 'terms_version' => config('clinic.terms_version')], $overrides);
    }

    private function staff(string $role = 'Recepcionista'): Usuario
    {
        return $this->verifiedAccount(['name' => $role, 'email' => strtolower($role).'@example.test',
            'role' => $role, 'status' => 'Activo', 'password' => 'PruebaSegura2026']);
    }

    private function patient(array $overrides = []): Paciente
    {
        return Paciente::create(array_replace(['first_name' => 'Ana', 'last_name' => 'Pérez', 'identity' => 'CI-123',
            'birth_date' => '1999-03-15', 'sex' => 'Femenino', 'phone' => '70000000', 'status' => 'Activo'], $overrides));
    }

    public function test_registration_is_atomic_normalized_and_cannot_assign_privileges(): void
    {
        $actor = $this->staff();
        $response = $this->actingAs($actor)->postJson('/api/patients/with-account', $this->registration(['email' => '  ANA@EXAMPLE.TEST  ',
            'identity' => ' ci-123 ', 'role' => 'Administrador', 'status' => 'Inactivo', 'patient_id' => 999]));
        $response->assertCreated()->assertJsonPath('account.username', 'ana.paciente')->assertJsonPath('status', 'Activo');
        $user = Usuario::findOrFail($response->json('account.id'));
        $this->assertSame('Paciente', $user->role);
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertTrue(Hash::check('PruebaSegura2026', $user->password));
        $this->assertSame('ana@example.test', $user->email);
        $this->assertSame('CI-123', $user->patient->identity);
        $code = null;
        Notification::assertSentTo($user, VerificarCorreoClinica::class, function ($notification) use (&$code) {
            $code = $notification->code;

            return true;
        });
        $this->postJson('/api/logout')->assertOk();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'PruebaSegura2026'])->assertForbidden();
        $this->getJson('/api/references')->assertUnauthorized();
        $this->postJson('/api/account/activate', ['email' => $user->email, 'code' => $code])->assertOk();
        $this->assertGuest();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'PruebaSegura2026'])->assertOk();
        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('audit_events', ['action' => 'account_created_by_staff', 'user_id' => $actor->id, 'record_id' => $user->patient_id]);
    }

    public function test_terms_confirmation_and_duplicate_identity_prevent_partial_registration(): void
    {
        $this->actingAs($this->staff());
        $this->postJson('/api/patients/with-account', $this->registration(['identity_verified' => false]))->assertUnprocessable()->assertJsonValidationErrors('identity_verified');
        $this->postJson('/api/patients/with-account', $this->registration(['password_confirmation' => 'OtraClave']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('patients', 0);
        $this->patient();
        $this->postJson('/api/patients/with-account', $this->registration(['identity' => ' ci-123 ']))->assertUnprocessable()->assertJsonValidationErrors('identity');
        $this->assertDatabaseCount('patients', 1);
        $this->assertSame(0, Usuario::where('role', 'Paciente')->count());
    }

    public function test_patient_cannot_download_catalogs_or_use_undocumented_contact_updates(): void
    {
        $other = $this->patient(['identity' => 'OTHER', 'first_name' => 'Otra']);
        $this->actingAs($this->staff());
        $response = $this->postJson('/api/patients/with-account', $this->registration())->assertCreated();
        $this->postJson('/api/logout')->assertOk();
        $id = $response->json('id');
        Usuario::findOrFail($response->json('account.id'))->forceFill(['email_verified_at' => now()])->save();
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
        $this->assertSame('Activo', Paciente::findOrFail($id)->status);
    }

    public function test_database_failure_rolls_back_patient_account_and_audit(): void
    {
        $existing = $this->staff();
        $auditCount = EventoAuditoria::count();
        try {
            app(CrearCuentaPaciente::class)->registrar($this->registration(['email' => $existing->email]), $existing);
            $this->fail('La unicidad del correo debió impedir la cuenta.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->errorInfo[0]);
        }
        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($auditCount, EventoAuditoria::count());
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
        $this->actingAs($this->staff());
        $response = $this->postJson('/api/patients/with-account', $this->registration())->assertCreated();
        $this->postJson('/api/logout')->assertOk();
        Usuario::findOrFail($response->json('account.id'))->forceFill(['email_verified_at' => now()])->save();
        $this->postJson('/api/login', ['email' => 'ana@example.test', 'password' => 'PruebaSegura2026'])->assertOk();
        Paciente::findOrFail($response->json('id'))->update(['status' => 'Inactivo']);
        $this->getJson('/api/references')->assertUnauthorized();
        $this->postJson('/api/login', ['email' => 'ana@example.test', 'password' => 'PruebaSegura2026'])->assertUnprocessable();
        $this->getJson('/api/session')->assertJsonPath('user', null);
    }

    public function test_only_reception_can_issue_verified_link_and_it_preserves_patient_id(): void
    {
        $patient = $this->patient();
        $reception = $this->staff();
        $this->actingAs($reception)->postJson('/api/patients/'.$patient->id.'/account', [])->assertUnprocessable();
        $response = $this->postJson('/api/patients/'.$patient->id.'/account', $this->registration())->assertCreated()->assertJsonPath('user.patient_id', $patient->id);
        $this->assertNull(Usuario::findOrFail($response->json('user.id'))->email_verified_at);
        $this->assertDatabaseCount('patients', 1);
        $this->postJson('/api/patients/'.$patient->id.'/account', $this->registration(['email' => 'otra@example.test', 'username' => 'otra.paciente']))->assertUnprocessable();
        $this->assertSame(1, Usuario::where('role', 'Paciente')->count());
        $this->assertStringNotContainsString('PruebaSegura2026', EventoAuditoria::all()->toJson());
    }

    public function test_link_rejects_wrong_identity_expired_and_revoked_codes(): void
    {
        $patient = $this->patient();
        $this->postJson('/api/register', $this->registration())->assertNotFound();
        $this->actingAs($this->staff())->postJson('/api/patients/'.$patient->id.'/account-invitation', ['identity_verified' => true])->assertNotFound();
        $this->assertSame(0, Usuario::where('role', 'Paciente')->count());
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
        $json = EventoAuditoria::all()->toJson();
        foreach (['PruebaSegura2026', 'Incorrecta', $admin->password, 'remember_token', 'password'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertDatabaseHas('audit_events', ['record_id' => $specialty, 'module' => 'specialties', 'user_id' => $admin->id]);
    }

    public function test_audit_database_rejects_update_delete_and_truncate(): void
    {
        $this->staff();
        $event = EventoAuditoria::firstOrFail();
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
        $before = EventoAuditoria::count();
        try {
            DB::transaction(function () {
                Especialidad::create(['name' => 'Rollback', 'status' => 'Activo']);
                throw new \RuntimeException('rollback esperado');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback esperado', $exception->getMessage());
        }
        $this->assertDatabaseMissing('specialties', ['name' => 'Rollback']);
        $this->assertSame($before, EventoAuditoria::count());
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
        $doctor = Medico::create(['registration' => 'DOC-1', 'first_name' => 'Médico', 'last_name' => 'Prueba', 'email' => 'doctor@example.test', 'phone' => '70000000', 'status' => 'Inactivo']);
        $user = $this->verifiedAccount(['name' => 'Médico', 'email' => 'medico@example.test', 'password' => 'PruebaSegura2026', 'role' => 'Médico', 'doctor_id' => $doctor->id, 'status' => 'Activo']);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'PruebaSegura2026'])->assertUnprocessable();
        $this->assertGuest();
    }
}
