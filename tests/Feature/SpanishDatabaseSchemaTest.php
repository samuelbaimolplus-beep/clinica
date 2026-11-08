<?php

namespace Tests\Feature;

use App\Database\SpanishPostgresConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SpanishDatabaseSchemaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_all_physical_tables_columns_and_named_objects_are_spanish(): void
    {
        $pdo = DB::connection()->getPdo();
        $tables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")->fetchAll(\PDO::FETCH_COLUMN);
        $expected = array_values(config('database_names.tables'));
        sort($expected);
        $this->assertSame($expected, $tables);
        $columns = $pdo->query("SELECT a.attname FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind='r' AND a.attnum>0 AND NOT a.attisdropped")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([], array_values(array_diff($columns, array_values(config('database_names.columns')))));
        $objects = $pdo->query("SELECT relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind IN ('i','S') UNION SELECT conname FROM pg_constraint c JOIN pg_namespace n ON n.oid=c.connamespace WHERE n.nspname='public'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([], array_values(array_diff($objects, array_values(config('database_object_names')))));
    }

    public function test_laravel_reads_writes_and_inspects_the_spanish_schema_without_changing_data(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('usuarios'));
        $this->assertTrue(Schema::hasColumns('users', ['email', 'password', 'created_at']));
        $this->assertTrue(Schema::hasColumn('usuarios', 'correo'));
        $this->assertFalse(Schema::hasTable('tabla_inexistente'));
        $this->assertSame('varchar', Schema::getColumnType('users', 'email'));
        $user = $this->verifiedAccount(['name' => 'date status users', 'username' => 'esquema.espanol',
            'email' => 'esquema@spanish.test', 'password' => 'ClaveSegura2026', 'role' => 'Administrador']);
        $stmt = DB::connection()->getPdo()->prepare('SELECT nombre, correo FROM public.usuarios WHERE id = ?');
        $stmt->execute([$user->id]);
        $this->assertSame(['nombre' => 'date status users', 'correo' => 'esquema@spanish.test'], $stmt->fetch(\PDO::FETCH_ASSOC));
        $this->assertSame('date status users', User::findOrFail($user->id)->name);
        $this->assertSame($user->id, User::where('email', $user->email)->cursor()->first()->id);
    }

    public function test_sql_translation_preserves_literals_comments_types_and_parameter_values(): void
    {
        $sql = <<<'SQL'
SELECT users.email, "status", date::date, 'users email status', $$users email$$
FROM users -- users email
WHERE name = ? /* status */
SQL;
        $expected = <<<'SQL'
SELECT usuarios.correo, "estado", fecha::date, 'users email status', $$users email$$
FROM usuarios -- users email
WHERE nombre = ? /* status */
SQL;
        $this->assertSame($expected, SpanishPostgresConnection::translateSql($sql));
        $this->assertSame('users email status', DB::selectOne("SELECT 'users email status' AS name")->name);
        $this->assertSame('users', DB::selectOne('SELECT ?::text AS name', ['users'])->name);
        $this->assertStringContainsString('primary key', SpanishPostgresConnection::translateSql('create table "users" ("id" serial primary key, "date" date)'));
    }

    public function test_renaming_is_reversible_and_preserves_accounts_and_foreign_keys(): void
    {
        $connection = DB::connection();
        $before = User::count();
        $migration = require database_path('migrations/2026_11_07_000030_translate_database_identifiers_to_spanish.php');
        try {
            $migration->down();
            $this->assertSame($before, User::count());
            $this->assertTrue(Schema::hasColumn('users', 'email'));
            $migration->up();
            $this->assertSame($before, User::count());
            $this->assertTrue(Schema::hasColumn('usuarios', 'correo'));
            $this->assertCount(2, Schema::getForeignKeys('users'));
        } finally {
            $connection->useSpanishSchema(true);
        }
    }
}
