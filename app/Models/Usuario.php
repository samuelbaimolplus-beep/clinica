<?php

/** Entidad de cuentas de usuario. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. */

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\RequisitosCuenta;
use App\Support\NombreUsuario;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\ValidationException;

#[Fillable(['name', 'username', 'email', 'password', 'role', 'status', 'doctor_id', 'patient_id', 'birth_date', 'terms_accepted_at', 'terms_version'])]
#[Hidden(['password', 'remember_token'])]
class Usuario extends Authenticatable
{
    protected $table = 'users';

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /** Configura eventos del modelo que deben aplicarse también fuera de los formularios. */
    protected static function booted(): void
    {
        static::updating(function (Usuario $usuario) {
            if ($usuario->isDirty(['birth_date', 'patient_id', 'role'])) {
                app(RequisitosCuenta::class)->exigirMayoriaDeEdad(app(RequisitosCuenta::class)->fechaNacimiento($usuario));
            }
            if ($usuario->isDirty('email')) {
                $usuario->email_verified_at = null;
            }
        });
        static::creating(function (Usuario $usuario) {
            $requisitosAcceso = app(RequisitosCuenta::class);
            $requisitosAcceso->exigirMayoriaDeEdad($requisitosAcceso->fechaNacimiento($usuario));
            if ($requisitosAcceso->requiereConsentimiento($usuario)) {
                throw ValidationException::withMessages(['terms_accepted' => 'El paciente debe aceptar los términos vigentes para tener una cuenta.']);
            }
            // Compatibilidad con altas por consola y clientes anteriores al campo username.
            if (! filled($usuario->username)) {
                $usuario->username = NombreUsuario::generar($usuario->email);
            }
        });
    }

    /** Normaliza el nombre de acceso antes de almacenarlo. */
    public function setUsernameAttribute(?string $valor): void
    {
        $this->attributes['username'] = $valor === null ? null : mb_strtolower(trim($valor));
    }

    /** Superadmin hereda la gestión del personal, sin suplantar identidades de pacientes o médicos. */
    public function tieneRolPersonal(string ...$roles): bool
    {
        return $this->role === 'Superadmin' || in_array($this->role, $roles, true);
    }

    /** Relaciona este registro con profesionales médicos mediante las claves del modelo. */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Medico::class);
    }

    /** Relaciona este registro con fichas de pacientes mediante las claves del modelo. */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    /**
     * Define las conversiones de los atributos del modelo.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'terms_accepted_at' => 'immutable_datetime',
        ];
    }
}
