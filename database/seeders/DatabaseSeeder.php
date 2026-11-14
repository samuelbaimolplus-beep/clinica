<?php

/** Datos ficticios para desarrollo o pruebas; no representan personas reales ni sustituyen el alta operativa. */

namespace Database\Seeders;

use App\Models\Usuario;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        Usuario::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
