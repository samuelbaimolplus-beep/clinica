<?php

/** Adapta identificadores SQL de Laravel al esquema español sin traducir valores ni literales. */

namespace App\Database;

use Closure;
use Illuminate\Database\PostgresConnection;

/**
 * Adapta las convenciones de Laravel al esquema físico en español.
 * No crea vistas ni tablas duplicadas. Los parámetros y literales no se alteran.
 */
class ConexionPostgresEspanol extends PostgresConnection
{
    private ?bool $spanishSchema = null;

    /** Fija explícitamente el modo de traducción del esquema para esta conexión. */
    public function usarEsquemaEspanol(bool $habilitado): void
    {
        $this->spanishSchema = $habilitado;
    }

    /** Detecta el esquema físico español una vez por conexión. */
    public function tieneEsquemaEspanol(): bool
    {
        return $this->spanishSchema ??= (bool) $this->getPdo()
            ->query("SELECT to_regclass('public.usuarios') IS NOT NULL")
            ->fetchColumn();
    }

    /** Combina la correspondencia de tablas y columnas con sus nombres físicos en español. */
    public static function identificadores(): array
    {
        return array_merge(config('database_names.tables'), config('database_names.columns'));
    }

    /** Traduce identificadores SQL y conserva literales, parámetros, comentarios y cuerpos de procedimientos. */
    public static function traducirSql(string $consulta): string
    {
        $nombres = self::identificadores();
        $esDdl = (bool) preg_match('/^\s*(?:create|alter|drop)\b/i', $consulta);
        // Literales, comentarios y bloques dollar-quoted se conservan íntegros.
        $patron = <<<'REGEX'
~'(?:''|\\.|[^'\\])*'|--[^\r\n]*|/\*[\s\S]*?\*/|\$(?:[a-zA-Z_][a-zA-Z0-9_]*)?\$[\s\S]*?\$(?:[a-zA-Z_][a-zA-Z0-9_]*)?\$|"(?:""|[^"])*"|(?<![a-zA-Z0-9_:])[a-zA-Z_][a-zA-Z0-9_]*~
REGEX;

        return preg_replace_callback($patron, static function ($coincidencia) use ($nombres, $esDdl) {
            $palabra = $coincidencia[0];
            if ($palabra[0] === '"') {
                $identificador = substr($palabra, 1, -1);

                return '"'.($nombres[$identificador] ?? $identificador).'"';
            }
            if ($esDdl && in_array(strtolower($palabra), ['key', 'date', 'identity', 'owner', 'connection', 'options', 'exception'], true)) {
                return $palabra;
            }

            return $nombres[$palabra] ?? $palabra;
        }, $consulta);
    }

    /** Proporciona el constructor de esquema compatible con identificadores españoles. */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new ConstructorPostgresEspanol($this);
    }

    /** Traduce la consulta y sus referencias de esquema antes de delegar su ejecución a PostgreSQL. */
    protected function run($consulta, $parametros, Closure $funcionRetorno)
    {
        if ($this->tieneEsquemaEspanol()) {
            // Las consultas al catálogo reciben nombres físicos como parámetros.
            if (preg_match('/\b(?:pg_catalog|information_schema|pg_get_serial_sequence)\b/i', $consulta)) {
                $nombres = self::identificadores();
                $parametros = array_map(static fn ($valor) => is_string($valor) ? ($nombres[$valor] ?? $valor) : $valor, $parametros);
            }
            $consulta = self::traducirSql($consulta);
        }

        return parent::run($consulta, $parametros, $funcionRetorno);
    }

    /** Adapta nombres de columnas del resultado a los atributos que esperan los modelos. */
    private function adaptarFilaAplicacion(mixed $fila): mixed
    {
        if (! is_object($fila) && ! is_array($fila)) {
            return $fila;
        }
        $nombres = array_flip(config('database_names.columns'));
        $adaptados = [];
        foreach ((array) $fila as $clave => $valor) {
            $adaptados[$nombres[$clave] ?? $clave] = $valor;
        }

        return is_object($fila) ? (object) $adaptados : $adaptados;
    }

    /** Ejecuta una lectura y adapta los nombres de columnas del resultado. */
    public function select($consulta, $parametros = [], $usarConexionLectura = true, array $opcionesLectura = [])
    {
        $filas = parent::select($consulta, $parametros, $usarConexionLectura, $opcionesLectura);

        return $this->tieneEsquemaEspanol() ? array_map($this->adaptarFilaAplicacion(...), $filas) : $filas;
    }

    /** Adapta cada conjunto de resultados devuelto por PostgreSQL. */
    public function selectResultSets($consulta, $parametros = [], $usarConexionLectura = true, array $opcionesLectura = [])
    {
        $conjuntos = parent::selectResultSets($consulta, $parametros, $usarConexionLectura, $opcionesLectura);

        return $this->tieneEsquemaEspanol()
            ? array_map(fn ($filas) => array_map($this->adaptarFilaAplicacion(...), $filas), $conjuntos)
            : $conjuntos;
    }

    /** Recorre los resultados adaptando sus columnas sin cargar el conjunto completo en memoria. */
    public function cursor($consulta, $parametros = [], $usarConexionLectura = true, array $opcionesLectura = [])
    {
        foreach (parent::cursor($consulta, $parametros, $usarConexionLectura, $opcionesLectura) as $fila) {
            yield $this->tieneEsquemaEspanol() ? $this->adaptarFilaAplicacion($fila) : $fila;
        }
    }
}
