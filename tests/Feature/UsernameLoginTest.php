<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Notifications\VerificarCorreoClinica;
use App\Support\NombreUsuario;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UsernameLoginTest extends TestCase
{
    use DatabaseTransactions;

    private function account(array $changes = []): Usuario
    {
        return $this->verifiedAccount(array_replace(['name' => 'Samuel de prueba', 'username' => 'samuel', 'email' => 'samuel@username.test',
            'password' => 'PruebaSegura2026', 'role' => 'Administrador', 'status' => 'Activo'], $changes));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('clinica_vida_salud_test', config('database.connections.pgsql.database'));
        Notification::fake();
    }

    public function test_login_accepts_username_or_email_ignoring_case_and_surrounding_spaces(): void
    {
        $user = $this->account();
        foreach (['samuel', '  SAMUEL  ', 'SAMUEL@USERNAME.TEST'] as $identifier) {
            $this->postJson('/api/login', ['email' => $identifier, 'password' => 'PruebaSegura2026'])->assertOk()->assertJsonPath('user.id', $user->id);
            $session = $this->getJson('/api/session')->assertOk()->assertJsonPath('user.id', $user->id);
            $this->assertStringContainsString('no-store', $session->headers->get('Cache-Control'));
            $this->postJson('/api/logout')->assertOk();
        }
    }

    public function test_username_does_not_bypass_password_account_status_or_missing_credentials(): void
    {
        $user = $this->account();
        $this->postJson('/api/login', ['email' => 'samuel', 'password' => 'Incorrecta'])->assertUnprocessable();
        $this->postJson('/api/login', ['email' => 'no_existe', 'password' => 'PruebaSegura2026'])->assertUnprocessable();
        $this->postJson('/api/login', ['email' => '', 'password' => 'PruebaSegura2026'])->assertUnprocessable();
        $user->update(['status' => 'Inactivo']);
        $this->postJson('/api/login', ['email' => 'samuel', 'password' => 'PruebaSegura2026'])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_staff_username_is_normalized_editable_and_unique_without_changing_permissions(): void
    {
        $admin = $this->account();
        $data = ['birth_date' => '1990-01-01', 'name' => 'Recepción', 'username' => '  RECEPCION.UNO  ', 'email' => 'recepcion@username.test', 'password' => 'PruebaSegura2026', 'role' => 'Recepcionista', 'status' => 'Activo'];
        $response = $this->actingAs($admin)->postJson('/api/entities/users', $data)->assertCreated()->assertJsonPath('username', 'recepcion.uno');
        $id = $response->json('id');
        $this->postJson('/api/entities/users', array_replace($data, ['email' => 'otro@username.test']))->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->putJson('/api/entities/users/'.$id, array_replace($data, ['username' => 'recepcion.dos']))->assertOk()->assertJsonPath('username', 'recepcion.dos');
        $this->assertSame('Recepcionista', Usuario::findOrFail($id)->role);
    }

    public function test_invalid_usernames_cannot_overlap_the_email_login_namespace(): void
    {
        $this->actingAs($this->account());
        $data = ['birth_date' => '1990-01-01', 'name' => 'Inválido', 'email' => 'nuevo@username.test', 'password' => 'PruebaSegura2026', 'role' => 'Recepcionista', 'status' => 'Activo'];
        foreach (['samuel@otro.test', 'con espacios', 'ab', '_prefijo', str_repeat('a', 51)] as $username) {
            $this->postJson('/api/entities/users', $data + ['username' => $username])->assertUnprocessable()->assertJsonValidationErrors('username');
        }
    }

    public function test_patient_username_is_assigned_at_clinic_and_account_requires_email_code(): void
    {
        $this->actingAs($this->account());
        $data = ['first_name' => 'Ana', 'last_name' => 'Paciente', 'username' => '  ANA.PACIENTE  ', 'identity' => 'USERNAME-01',
            'birth_date' => '2000-01-01', 'sex' => 'Femenino', 'phone' => '70000000', 'email' => 'ana@username.test',
            'password' => 'PruebaSegura2026', 'password_confirmation' => 'PruebaSegura2026', 'identity_verified' => true, 'terms_accepted' => true, 'terms_version' => config('clinic.terms_version')];
        $response = $this->postJson('/api/patients/with-account', $data)->assertCreated()->assertJsonPath('account.username', 'ana.paciente');
        $user = Usuario::findOrFail($response->json('account.id'));
        $code = null;
        Notification::assertSentTo($user, VerificarCorreoClinica::class, function ($notification) use (&$code) {
            $code = $notification->code;

            return true;
        });
        $this->postJson('/api/logout')->assertOk();
        $this->postJson('/api/login', ['email' => 'ana.paciente', 'password' => 'PruebaSegura2026'])->assertForbidden();
        $this->postJson('/api/account/activate', ['email' => $user->email, 'code' => $code])->assertOk();
        $this->postJson('/api/login', ['email' => 'ANA.PACIENTE', 'password' => 'PruebaSegura2026'])->assertOk();
        $this->getJson('/api/entities/users')->assertForbidden();
    }

    public function test_changing_username_revokes_existing_session_and_old_identifier_stops_working(): void
    {
        $user = $this->account();
        $this->postJson('/api/login', ['email' => 'samuel', 'password' => 'PruebaSegura2026'])->assertOk();
        $user->update(['username' => 'samuel.nuevo']);
        $this->getJson('/api/references')->assertUnauthorized();
        $this->postJson('/api/login', ['email' => 'samuel', 'password' => 'PruebaSegura2026'])->assertUnprocessable();
        $this->postJson('/api/login', ['email' => 'samuel.nuevo', 'password' => 'PruebaSegura2026'])->assertOk();
    }

    public function test_older_clients_can_create_accounts_without_username_and_receive_distinct_valid_defaults(): void
    {
        $first = $this->account(['username' => null, 'email' => 'mismo@uno.test']);
        $second = $this->account(['username' => null, 'email' => 'mismo@dos.test']);
        $this->assertNotSame($first->username, $second->username);
        $this->assertMatchesRegularExpression(NombreUsuario::PATTERN, $first->username);
        $this->assertMatchesRegularExpression(NombreUsuario::PATTERN, $second->username);
    }

    public function test_postgresql_enforces_username_uniqueness_and_canonical_format(): void
    {
        $this->account();
        $other = $this->account(['username' => 'otro', 'email' => 'otro@username.test']);
        foreach (['samuel', 'SAMUEL', 'con espacios'] as $username) {
            try {
                DB::transaction(fn () => DB::table('users')->where('id', $other->id)->update(['username' => $username]));
                $this->fail('PostgreSQL debía rechazar el nombre de usuario.');
            } catch (QueryException $e) {
                $this->assertContains($e->errorInfo[0], ['23505', '23514']);
            }
        }
        $this->assertSame('otro', $other->fresh()->username);
    }

    public function test_user_chooses_own_login_name_without_changing_password_permissions_or_other_accounts(): void
    {
        $user = $this->account(['role' => 'Recepcionista']);
        $other = $this->account(['username' => 'otro', 'email' => 'otro@username.test']);
        $oldHash = $user->password;
        $this->postJson('/api/login', ['email' => 'samuel', 'password' => 'PruebaSegura2026'])->assertOk();
        $this->patchJson('/api/account/username', ['username' => '  MI.USUARIO  ', 'current_password' => 'PruebaSegura2026', 'id' => $other->id, 'role' => 'Superadmin', 'password' => 'OtraClave2026'])
            ->assertOk()->assertJsonPath('user.username', 'mi.usuario')->assertJsonPath('user.role', 'Recepcionista')->assertJsonMissingPath('user.password');
        $this->assertSame($oldHash, $user->fresh()->password);
        $this->assertSame('otro', $other->fresh()->username);
        $this->getJson('/api/session')->assertOk()->assertJsonPath('user.username', 'mi.usuario');
        $this->postJson('/api/logout')->assertOk();
        $this->postJson('/api/login', ['email' => 'samuel', 'password' => 'PruebaSegura2026'])->assertUnprocessable();
        $this->postJson('/api/login', ['email' => 'mi.usuario', 'password' => 'PruebaSegura2026'])->assertOk();
    }

    public function test_choosing_login_name_requires_authentication_current_password_and_availability(): void
    {
        $user = $this->account();
        $this->account(['username' => 'ocupado', 'email' => 'otro@username.test']);
        $this->patchJson('/api/account/username', ['username' => 'nuevo', 'current_password' => 'PruebaSegura2026'])->assertUnauthorized();
        $this->actingAs($user)->patchJson('/api/account/username', ['username' => 'nuevo', 'current_password' => 'Incorrecta'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->patchJson('/api/account/username', ['username' => 'OCUPADO', 'current_password' => 'PruebaSegura2026'])->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->patchJson('/api/account/username', ['username' => 'con espacios', 'current_password' => 'PruebaSegura2026'])->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->assertSame('samuel', $user->fresh()->username);
    }

    public function test_keeping_the_same_login_name_is_allowed(): void
    {
        $this->actingAs($this->account())->patchJson('/api/account/username', ['username' => 'SAMUEL', 'current_password' => 'PruebaSegura2026'])->assertOk()->assertJsonPath('user.username', 'samuel');
    }
}
