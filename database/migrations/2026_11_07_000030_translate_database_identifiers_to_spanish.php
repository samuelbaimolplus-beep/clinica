<?php

/** Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. */

use App\Database\SpanishPostgresConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Aplica el cambio de esquema correspondiente a esta migración. */
    public function up(): void
    {
        $this->renameSchema(false);
    }

    /** Describe la reversión de esta migración; se utiliza únicamente al solicitar un rollback. */
    public function down(): void
    {
        $this->renameSchema(true);
    }

    /** Implementa la operación de renameSchema dentro de la responsabilidad indicada al inicio de este archivo. */
    private function renameSchema(bool $reverse): void
    {
        $connection = DB::connection();
        if (! $connection instanceof SpanishPostgresConnection) {
            throw new RuntimeException('Se requiere la conexión PostgreSQL del proyecto.');
        }
        $tables = config('database_names.tables');
        $columns = config('database_names.columns');
        $objects = config('database_object_names');
        if ($reverse) {
            $tables = array_flip($tables);
            $columns = array_flip($columns);
            $objects = array_flip($objects);
        }
        $pdo = $connection->getPdo();
        $quote = static fn (string $name) => '"'.str_replace('"', '""', $name).'"';
        $rows = $pdo->query("SELECT c.relname AS tabla, a.attname AS columna FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace JOIN pg_attribute a ON a.attrelid=c.oid WHERE n.nspname='public' AND c.relkind='r' AND a.attnum>0 AND NOT a.attisdropped ORDER BY c.relname,a.attnum")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $old = $row['columna'];
            if (isset($tables[$row['tabla']], $columns[$old]) && $old !== $columns[$old]) {
                $pdo->exec('ALTER TABLE public.'.$quote($row['tabla']).' RENAME COLUMN '.$quote($old).' TO '.$quote($columns[$old]));
            }
        }
        $constraints = $pdo->query("SELECT c.relname AS tabla, con.conname AS nombre FROM pg_constraint con JOIN pg_class c ON c.oid=con.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' ORDER BY c.relname,con.conname")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($constraints as $constraint) {
            if (isset($objects[$constraint['nombre']])) {
                $pdo->exec('ALTER TABLE public.'.$quote($constraint['tabla']).' RENAME CONSTRAINT '.$quote($constraint['nombre']).' TO '.$quote($objects[$constraint['nombre']]));
            }
        }
        // Las PK y UNIQUE ya renombraron sus índices asociados.
        $relations = $pdo->query("SELECT c.relname AS nombre, c.relkind AS tipo FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind IN ('i','S') ORDER BY c.relname")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($relations as $relation) {
            if (isset($objects[$relation['nombre']])) {
                $pdo->exec('ALTER '.($relation['tipo'] === 'S' ? 'SEQUENCE' : 'INDEX').' public.'.$quote($relation['nombre']).' RENAME TO '.$quote($objects[$relation['nombre']]));
            }
        }
        $functions = $pdo->query("SELECT p.proname AS nombre FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public' AND p.pronargs=0")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($functions as $function) {
            if (isset($objects[$function])) {
                $pdo->exec('ALTER FUNCTION public.'.$quote($function).'() RENAME TO '.$quote($objects[$function]));
            }
        }
        $triggers = $pdo->query("SELECT c.relname AS tabla,t.tgname AS nombre FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND NOT t.tgisinternal")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($triggers as $trigger) {
            if (isset($objects[$trigger['nombre']])) {
                $pdo->exec('ALTER TRIGGER '.$quote($trigger['nombre']).' ON public.'.$quote($trigger['tabla']).' RENAME TO '.$quote($objects[$trigger['nombre']]));
            }
        }
        foreach ($tables as $old => $new) {
            $pdo->exec('ALTER TABLE public.'.$quote($old).' RENAME TO '.$quote($new));
        }
        $connection->useSpanishSchema(! $reverse);
    }
};
