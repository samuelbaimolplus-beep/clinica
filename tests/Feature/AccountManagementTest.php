<?php

namespace Tests\Feature;

use App\Http\Controllers\AccountManagementController;
use App\Models\AuditEvent;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use DatabaseTransactions;

    private User $superadmin;

    private User $patientUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('clinica_vida_salud_test', config('database.connections.pgsql.database'));
        $this->superadmin = $this->account('Superadmin');
        $patient = Patient::create(['first_name' => 'Ana', 'last_name' => 'Prueba', 'birth_date' => '2000-01-01', 'sex' => 'Femenino', 'phone' => '70000000', 'status' => 'Activo']);
        $this->patientUser = User::create(['name' => 'Ana paciente', 'email' => 'paciente@access.test', 'password' => 'AnteriorSegura2026',
            'role' => 'Paciente', 'patient_id' => $patient->id, 'status' => 'Activo']);
        $this->patientUser->forceFill(['email_verified_at' => now()])->save();
    }

    private function account(string $role): User
    {
        $doctorId = $role === 'Médico' ? Doctor::create(['registration' => 'ACCESS-01', 'first_name' => 'Ana', 'last_name' => 'Médica', 'email' => 'doctor@access.test', 'phone' => '70000001', 'status' => 'Activo'])->id : null;

        return User::create(['name' => $role, 'email' => 'cuenta'.User::count().'@access.test', 'password' => 'AnteriorSegura2026', 'role' => $role, 'doctor_id' => $doctorId, 'status' => 'Activo']);
    }

    public function test_superadmin_lists_staff_and_patient_accounts_without_passwords_or_hashes(): void
    {
        $response = $this->actingAs($this->superadmin)->getJson('/api/supervision/accounts')->assertOk();
        $response->assertJsonFragment(['id' => $this->patientUser->id, 'role' => 'Paciente']);
        foreach ($response->json() as $account) {
            $this->assertSame(['id', 'name', 'username', 'email', 'role', 'status'], array_keys($account));
        }
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_every_ordinary_role_and_visitors_are_denied_account_management(): void
    {
        $this->getJson('/api/supervision/accounts')->assertUnauthorized();
        foreach ([$this->account('Administrador'), $this->account('Recepcionista'), $this->account('Médico'), $this->patientUser] as $actor) {
            $this->actingAs($actor)->getJson('/api/supervision/accounts')->assertForbidden();
            $this->postJson('/api/supervision/accounts/'.$this->superadmin->id.'/password', ['password' => 'NuevaSegura2026', 'password_confirmation' => 'NuevaSegura2026'])->assertForbidden();
        }
        $this->assertTrue(Hash::check('AnteriorSegura2026', $this->superadmin->fresh()->password));
    }

    public function test_superadmin_changes_passwords_of_staff_and_patients_without_exposing_or_auditing_the_secret(): void
    {
        $this->actingAs($this->superadmin);
        foreach ([$this->account('Recepcionista'), $this->patientUser] as $target) {
            $response = $this->postJson('/api/supervision/accounts/'.$target->id.'/password', ['password' => 'NuevaSegura2026', 'password_confirmation' => 'NuevaSegura2026'])->assertOk();
            $this->assertTrue(Hash::check('NuevaSegura2026', $target->fresh()->password));
            $this->assertFalse(Hash::check('AnteriorSegura2026', $target->fresh()->password));
            $response->assertJsonMissingPath('password')->assertJsonMissingPath('password_hash');
            $this->assertDatabaseHas('audit_events', ['user_id' => $this->superadmin->id, 'action' => 'password_reset_by_superadmin', 'record_id' => $target->id]);
        }
        $this->assertStringNotContainsString('NuevaSegura2026', AuditEvent::all()->toJson());
    }

    public function test_password_confirmation_strength_and_existing_target_are_required(): void
    {
        $this->actingAs($this->superadmin);
        foreach ([['password' => 'corta', 'password_confirmation' => 'corta'], ['password' => 'NuevaSegura2026', 'password_confirmation' => 'DistintaSegura2026']] as $data) {
            $this->postJson('/api/supervision/accounts/'.$this->patientUser->id.'/password', $data)->assertUnprocessable();
        }
        $this->postJson('/api/supervision/accounts/999999999/password', ['password' => 'NuevaSegura2026', 'password_confirmation' => 'NuevaSegura2026'])->assertNotFound();
        $this->assertTrue(Hash::check('AnteriorSegura2026', $this->patientUser->fresh()->password));
    }

    public function test_password_reset_revokes_the_targets_previous_session(): void
    {
        $this->postJson('/api/login', ['email' => $this->patientUser->username, 'password' => 'AnteriorSegura2026'])->assertOk();
        // Ejecutar la misma operación administrativa sin reemplazar la sesión del paciente del navegador.
        $request = Request::create('/api/supervision/accounts/'.$this->patientUser->id.'/password', 'POST', ['password' => 'NuevaSegura2026', 'password_confirmation' => 'NuevaSegura2026']);
        $request->setUserResolver(fn () => $this->superadmin);
        app(AccountManagementController::class)->password($request, $this->patientUser->id);
        $this->getJson('/api/references')->assertUnauthorized();
        $this->postJson('/api/login', ['email' => $this->patientUser->username, 'password' => 'AnteriorSegura2026'])->assertUnprocessable();
        $this->postJson('/api/login', ['email' => $this->patientUser->username, 'password' => 'NuevaSegura2026'])->assertOk();
    }
}
