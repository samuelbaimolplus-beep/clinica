<?php

namespace App\Database;

use Closure;
use Illuminate\Database\PostgresConnection;

/**
 * Adapta las convenciones de Laravel al esquema físico en español.
 * No crea vistas ni tablas duplicadas. Los parámetros y literales no se alteran.
 */
class SpanishPostgresConnection extends PostgresConnection
{
    private ?bool $spanishSchema = null;

    public function useSpanishSchema(bool $enabled): void
    {
        $this->spanishSchema = $enabled;
    }

    public function hasSpanishSchema(): bool
    {
        return $this->spanishSchema ??= (bool) $this->getPdo()
            ->query("SELECT to_regclass('public.usuarios') IS NOT NULL")
            ->fetchColumn();
    }

    public static function identifiers(): array
    {
        return array_merge(config('database_names.tables'), config('database_names.columns'));
    }

    public static function translateSql(string $query): string
    {
        $names = self::identifiers();
        $ddl = (bool) preg_match('/^\s*(?:create|alter|drop)\b/i', $query);
        // Literales, comentarios y bloques dollar-quoted se conservan íntegros.
        $pattern = <<<'REGEX'
~'(?:''|\\.|[^'\\])*'|--[^\r\n]*|/\*[\s\S]*?\*/|\$(?:[a-zA-Z_][a-zA-Z0-9_]*)?\$[\s\S]*?\$(?:[a-zA-Z_][a-zA-Z0-9_]*)?\$|"(?:""|[^"])*"|(?<![a-zA-Z0-9_:])[a-zA-Z_][a-zA-Z0-9_]*~
REGEX;

        return preg_replace_callback($pattern, static function ($match) use ($names, $ddl) {
            $word = $match[0];
            if ($word[0] === '"') {
                $identifier = substr($word, 1, -1);

                return '"'.($names[$identifier] ?? $identifier).'"';
            }
            if ($ddl && in_array(strtolower($word), ['key', 'date', 'identity', 'owner', 'connection', 'options', 'exception'], true)) {
                return $word;
            }

            return $names[$word] ?? $word;
        }, $query);
    }

    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new SpanishPostgresBuilder($this);
    }

    protected function run($query, $bindings, Closure $callback)
    {
        if ($this->hasSpanishSchema()) {
            // Las consultas al catálogo reciben nombres físicos como parámetros.
            if (preg_match('/\b(?:pg_catalog|information_schema|pg_get_serial_sequence)\b/i', $query)) {
                $names = self::identifiers();
                $bindings = array_map(static fn ($value) => is_string($value) ? ($names[$value] ?? $value) : $value, $bindings);
            }
            $query = self::translateSql($query);
        }

        return parent::run($query, $bindings, $callback);
    }

    private function applicationRow(mixed $row): mixed
    {
        if (! is_object($row) && ! is_array($row)) {
            return $row;
        }
        $names = array_flip(config('database_names.columns'));
        $mapped = [];
        foreach ((array) $row as $key => $value) {
            $mapped[$names[$key] ?? $key] = $value;
        }

        return is_object($row) ? (object) $mapped : $mapped;
    }

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $rows = parent::select($query, $bindings, $useReadPdo, $fetchUsing);

        return $this->hasSpanishSchema() ? array_map($this->applicationRow(...), $rows) : $rows;
    }

    public function selectResultSets($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $sets = parent::selectResultSets($query, $bindings, $useReadPdo, $fetchUsing);

        return $this->hasSpanishSchema()
            ? array_map(fn ($rows) => array_map($this->applicationRow(...), $rows), $sets)
            : $sets;
    }

    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        foreach (parent::cursor($query, $bindings, $useReadPdo, $fetchUsing) as $row) {
            yield $this->hasSpanishSchema() ? $this->applicationRow($row) : $row;
        }
    }
}
