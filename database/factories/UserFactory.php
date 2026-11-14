<?php

/** Datos ficticios para desarrollo o pruebas; no representan personas reales ni sustituyen el alta operativa. */

namespace Database\Factories;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<Usuario>
 */
class UserFactory extends Factory
{
    protected $model = Usuario::class;

    /**
     * Contraseña actual utilizada por la fábrica de datos de prueba.
     */
    protected static ?string $password;

    /**
     * Define los datos predeterminados del modelo.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'birth_date' => '1990-01-01',
            'terms_accepted_at' => now(),
            'terms_version' => config('clinic.terms_version'),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Prepara una cuenta cuyo correo todavía no ha sido verificado.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
