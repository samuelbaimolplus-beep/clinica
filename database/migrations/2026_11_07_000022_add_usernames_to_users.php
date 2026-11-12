<?php

/** Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. */

use App\Support\Username;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Aplica el cambio de esquema correspondiente a esta migración. */
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('username', 50)->nullable());
        DB::table('users')->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                $base = Username::base($user->email);
                $username = $base;
                $suffix = 1;
                while (DB::table('users')->where('username', $username)->exists()) {
                    $ending = '_'.$suffix++;
                    $username = substr($base, 0, 50 - strlen($ending)).$ending;
                }
                DB::table('users')->where('id', $user->id)->update(['username' => $username]);
            }
        });
        DB::statement('ALTER TABLE users ALTER COLUMN username SET NOT NULL');
        DB::statement('CREATE UNIQUE INDEX users_username_unique ON users (lower(username))');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_username_format CHECK (username = lower(btrim(username)) AND username ~ '^[a-z0-9][a-z0-9._-]{2,49}$')");
    }

    /** Describe la reversión de esta migración; se utiliza únicamente al solicitar un rollback. */
    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_username_format');
        DB::statement('DROP INDEX users_username_unique');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('username'));
    }
};
