<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use App\Services\Patients\UpdatePatientContact;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('clinica_vida_salud_test', config('database.connections.pgsql.database'));
    }

    public static function roles(): array
    {
        return array_map(fn ($role) => [$role], ['Administrador', 'Recepcionista', 'Médico', 'Paciente']);
    }

    private function account(string $role = 'Recepcionista'): User
    {
        $data = ['name' => 'Usuario de prueba', 'email' => 'usuario@example.test', 'password' => 'AnteriorSegura2026',
            'role' => $role, 'status' => 'Activo'];
        if ($role === 'Médico') {
            $data['doctor_id'] = Doctor::create(['registration' => 'REG-1', 'first_name' => 'Médico', 'last_name' => 'Prueba',
                'phone' => '70000000', 'email' => 'medico@example.test', 'status' => 'Activo'])->id;
        }
        if ($role === 'Paciente') {
            $data['patient_id'] = Patient::create(['identity' => 'CI-ACCOUNT', 'first_name' => 'Ana', 'last_name' => 'Prueba',
                'birth_date' => '1990-01-01', 'sex' => 'Femenino', 'phone' => '70000000', 'status' => 'Activo'])->id;
        }

        return User::create($data);
    }

    private function newPassword(array $overrides = []): array
    {
        return array_replace(['current_password' => 'AnteriorSegura2026', 'password' => 'NuevaSegura2026',
            'password_confirmation' => 'NuevaSegura2026'], $overrides);
    }

    #[DataProvider('roles')]
    public function test_every_role_can_change_own_password_and_keep_only_current_session(string $role): void
    {
        $user = $this->account($role);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'AnteriorSegura2026'])->assertOk();
        $oldSession = ['clinic' => session('clinic')];
        $oldCsrf = csrf_token();
        $this->putJson('/api/profile/password', $this->newPassword(['role' => 'Administrador', 'user_id' => 999]))
            ->assertOk()->assertJsonStructure(['csrf', 'message'])->assertJsonMissingPath('password');
        $this->assertNotSame($oldCsrf, csrf_token());
        $this->getJson('/api/profile')->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('user.role', $role);
        $user->refresh();
        $this->assertTrue(Hash::check('NuevaSegura2026', $user->password));
        $this->assertFalse(Hash::check('AnteriorSegura2026', $user->password));
        $this->assertDatabaseHas('audit_events', ['user_id' => $user->id, 'action' => 'password_changed', 'module' => 'auth']);
        $this->withSession($oldSession)->getJson('/api/profile')->assertUnauthorized()->assertJsonStructure(['csrf']);
        $this->assertGuest();
        $this->assertDatabaseHas('audit_events', ['user_id' => $user->id, 'action' => 'session_revoked']);
        $json = AuditEvent::all()->toJson();
        foreach (['AnteriorSegura2026', 'NuevaSegura2026', $user->password, $oldSession['clinic']['auth_fingerprint']] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_password_change_rejects_guest_wrong_current_short_unconfirmed_and_unchanged_passwords(): void
    {
        $this->putJson('/api/profile/password', $this->newPassword())->assertUnauthorized();
        $user = $this->account();
        $oldHash = $user->password;
        $this->actingAs($user);
        $this->putJson('/api/profile/password', $this->newPassword(['current_password' => 'Incorrecta']))->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/profile/password', $this->newPassword(['password_confirmation' => 'Distinta']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson('/api/profile/password', $this->newPassword(['password' => 'Corta', 'password_confirmation' => 'Corta']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson('/api/profile/password', $this->newPassword(['password' => 'AnteriorSegura2026', 'password_confirmation' => 'AnteriorSegura2026']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame($oldHash, $user->fresh()->password);
        $this->assertDatabaseMissing('audit_events', ['action' => 'password_changed']);
    }

    public function test_password_changed_by_administrator_revokes_session_even_on_public_session_endpoint(): void
    {
        $user = $this->account();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'AnteriorSegura2026'])->assertOk();
        $user->update(['password' => 'AsignadaPorAdmin2026']);
        $this->getJson('/api/session')->assertOk()->assertJsonPath('user', null)->assertJsonStructure(['csrf']);
        $this->assertGuest();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'AnteriorSegura2026'])->assertUnprocessable();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'AsignadaPorAdmin2026'])->assertOk();
    }

    public function test_role_changes_require_new_login_before_using_new_permissions(): void
    {
        $user = $this->account();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'AnteriorSegura2026'])->assertOk();
        $user->update(['role' => 'Administrador']);
        $this->getJson('/api/entities/users')->assertUnauthorized();
        $this->assertGuest();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'AnteriorSegura2026'])->assertOk();
        $this->getJson('/api/entities/users')->assertOk();
    }

    public function test_contact_update_preserves_reception_changes_even_with_stale_loaded_profile(): void
    {
        $user = $this->account('Paciente');
        $user->load('patient');
        Patient::whereKey($user->patient_id)->firstOrFail()->update(['last_name' => 'Corregido por recepción']);
        $patient = app(UpdatePatientContact::class)->handle($user, ['phone' => '79999999', 'address' => 'Nueva dirección',
            'last_name' => 'Valor antiguo', 'status' => 'Inactivo', 'identity' => 'NO-CAMBIAR']);
        $this->assertSame('Corregido por recepción', $patient->last_name);
        $this->assertSame('79999999', $patient->phone);
        $this->assertSame('CI-ACCOUNT', $patient->identity);
        $this->assertSame('Activo', $patient->status);
    }
}
