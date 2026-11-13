<?php

/** Entidad de cuentas de usuario. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. */

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\RequisitosCuenta;
use App\Support\Username;
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
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Configura eventos del modelo que deben aplicarse también fuera de los formularios. */
    protected static function booted(): void
    {
        static::updating(function (User $user) {
            if ($user->isDirty(['birth_date', 'patient_id', 'role'])) {
                app(RequisitosCuenta::class)->exigirMayoriaDeEdad(app(RequisitosCuenta::class)->fechaNacimiento($user));
            }
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }
        });
        static::creating(function (User $user) {
            $eligibility = app(RequisitosCuenta::class);
            $eligibility->exigirMayoriaDeEdad($eligibility->fechaNacimiento($user));
            if ($eligibility->requiereConsentimiento($user)) {
                throw ValidationException::withMessages(['terms_accepted' => 'El paciente debe aceptar los términos vigentes para tener una cuenta.']);
            }
            // Compatibilidad con altas por consola y clientes anteriores al campo username.
            if (! filled($user->username)) {
                $user->username = Username::generated($user->email);
            }
        });
    }

    /** Normaliza el nombre de acceso antes de almacenarlo. */
    public function setUsernameAttribute(?string $value): void
    {
        $this->attributes['username'] = $value === null ? null : mb_strtolower(trim($value));
    }

    /** Superadmin hereda la gestión del personal, sin suplantar identidades de pacientes o médicos. */
    public function hasStaffRole(string ...$roles): bool
    {
        return $this->role === 'Superadmin' || in_array($this->role, $roles, true);
    }

    /** Relaciona este registro con profesionales médicos mediante las claves del modelo. */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /** Relaciona este registro con fichas de pacientes mediante las claves del modelo. */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Get the attributes that should be cast.
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
