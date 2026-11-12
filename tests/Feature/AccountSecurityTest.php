<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Patient;
use App\Models\User;
use App\Notifications\VerifyClinicEmail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('clinica_vida_salud_test', config('database.connections.pgsql.database'));
        Notification::fake();
    }

    private function account(bool $verified = true, bool $patient = false): User
    {
        $patientId = $patient ? Patient::create(['identity' => 'ACCOUNT-V6', 'first_name' => 'Ana', 'last_name' => 'Prueba',
            'birth_date' => '2000-01-01', 'sex' => 'Femenino', 'phone' => '70000000', 'status' => 'Activo'])->id : null;
        $user = User::create(['birth_date' => '1990-01-01', 'terms_accepted_at' => now(), 'terms_version' => config('clinic.terms_version'), 'name' => 'Cuenta de prueba', 'email' => 'cuenta@example.test', 'password' => 'AnteriorSegura2026',
            'role' => $patient ? 'Paciente' : 'Recepcionista', 'patient_id' => $patientId, 'status' => 'Activo']);
        if ($verified) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }

    public function test_patient_login_requires_email_code_and_activation_does_not_log_in(): void
    {
        $user = $this->account(false, true);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'AnteriorSegura2026'])->assertForbidden();
        $this->postJson('/api/account/email', ['email' => $user->email, 'purpose' => 'verify'])->assertOk();
        $code = null;
        Notification::assertSentTo($user, VerifyClinicEmail::class, function ($notification) use (&$code) {
            $code = $notification->code;

            return true;
        });
        $this->get('/account/verify/'.$user->id.'/'.sha1($user->email))->assertNotFound();
        $this->postJson('/api/account/activate', ['email' => $user->email, 'code' => $code])->assertOk();
        $this->assertGuest();
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'AnteriorSegura2026'])->assertOk();
    }

    public function test_unknown_inactive_and_unverified_accounts_do_not_receive_reset_links_or_leak_identity(): void
    {
        $user = $this->account(false);
        $body = $this->postJson('/api/account/email', ['email' => 'unknown@example.test', 'purpose' => 'recover'])->assertOk()->json();
        $this->postJson('/api/account/email', ['email' => $user->email, 'purpose' => 'recover'])->assertExactJson($body);
        Notification::assertSentTo($user, VerifyClinicEmail::class);
        Notification::assertNotSentTo($user, ResetPassword::class);
        $user->update(['status' => 'Inactivo']);
        Notification::fake();
        $this->postJson('/api/account/email', ['email' => $user->email, 'purpose' => 'recover'])->assertExactJson($body);
        Notification::assertNothingSent();
    }

    public function test_recovery_uses_one_time_email_token_hash_and_revokes_old_sessions(): void
    {
        $user = $this->account();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'AnteriorSegura2026'])->assertOk();
        $oldSession = ['clinic' => session('clinic')];
        $this->postJson('/api/logout')->assertOk();
        $this->postJson('/api/account/email', ['email' => $user->email, 'purpose' => 'recover'])->assertOk();
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'NuevaSegura2026', 'password_confirmation' => 'NuevaSegura2026'];
        $this->postJson('/api/account/reset', $data)->assertOk();
        $this->assertGuest();
        $this->assertTrue(Hash::check('NuevaSegura2026', $user->fresh()->password));
        $this->postJson('/api/account/reset', $data)->assertUnprocessable();
        $this->actingAs($user->fresh())->withSession($oldSession)->getJson('/api/references')->assertUnauthorized();
        $this->assertStringNotContainsString($token, AuditEvent::all()->toJson());
        $this->assertDatabaseHas('audit_events', ['action' => 'password_recovered', 'user_id' => $user->id]);
    }

    public function test_invalid_expired_or_unconfirmed_recovery_does_not_change_password(): void
    {
        $user = $this->account();
        $hash = $user->password;
        $token = Password::createToken($user);
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'NuevaSegura2026', 'password_confirmation' => 'OtraSegura2026'];
        $this->postJson('/api/account/reset', $data)->assertUnprocessable();
        $data['password_confirmation'] = $data['password'];
        $this->travel(61)->minutes();
        $this->postJson('/api/account/reset', $data)->assertUnprocessable();
        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_undocumented_profile_and_contact_operations_are_unavailable(): void
    {
        $this->actingAs($this->account());
        $this->getJson('/api/profile')->assertNotFound();
        $this->putJson('/api/profile', ['phone' => '70000000'])->assertNotFound();
        $this->putJson('/api/profile/password', [])->assertNotFound();
        $this->getJson('/api/session')->assertJsonMissingPath('access.PERFIL');
    }

    public function test_password_and_role_changes_revoke_existing_sessions(): void
    {
        $user = $this->account();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'AnteriorSegura2026'])->assertOk();
        $user->update(['password' => 'AsignadaPorAdmin2026']);
        $this->getJson('/api/session')->assertJsonPath('user', null);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'AsignadaPorAdmin2026'])->assertOk();
        $user->update(['role' => 'Administrador']);
        $this->getJson('/api/entities/users')->assertUnauthorized();
    }
}
