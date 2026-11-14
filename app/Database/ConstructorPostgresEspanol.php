<?php

/** Traduce introspección del esquema para que migraciones y modelos mantengan convenciones Laravel. */

namespace App\Database;

use Illuminate\Database\Schema\PostgresBuilder;

class ConstructorPostgresEspanol extends PostgresBuilder
{
    /** Resuelve el nombre físico de una tabla para consultar el catálogo de PostgreSQL. */
    private function tablaFisica(string $tabla): string
    {
        if (! $this->connection->tieneEsquemaEspanol()) {
            return $tabla;
        }
        $partes = explode('.', $tabla);
        $ultimo = array_key_last($partes);
        $partes[$ultimo] = config('database_names.tables')[$partes[$ultimo]] ?? $partes[$ultimo];

        return implode('.', $partes);
    }

    /** Distingue nombres convencionales de Laravel y nombres físicos del esquema. */
    private function usaNombresAplicacion(string $tabla): bool
    {
        $partes = explode('.', $tabla);

        return $this->connection->tieneEsquemaEspanol() && isset(config('database_names.tables')[end($partes)]);
    }

    /** Resuelve el nombre físico de una columna durante la introspección. */
    private function columnaEsquema(string $tabla, string $columna): string
    {
        if (! $this->connection->tieneEsquemaEspanol()) {
            return $columna;
        }
        $nombres = $this->usaNombresAplicacion($tabla)
            ? array_flip(config('database_names.columns'))
            : config('database_names.columns');

        return $nombres[$columna] ?? $columna;
    }

    /** Comprueba la existencia de una tabla usando su nombre físico. */
    public function hasTable($tabla)
    {
        return parent::hasTable($this->tablaFisica($tabla));
    }

    /** Consulta las columnas de la tabla y adapta sus identificadores. */
    public function getColumns($tabla)
    {
        $columnas = parent::getColumns($this->tablaFisica($tabla));
        if ($this->usaNombresAplicacion($tabla)) {
            $nombres = array_flip(config('database_names.columns'));
            foreach ($columnas as &$columna) {
                $columna['name'] = $nombres[$columna['name']] ?? $columna['name'];
            }
        }

        return $columnas;
    }

    /** Consulta índices de una tabla para las migraciones e inspección del esquema. */
    public function getIndexes($tabla)
    {
        return parent::getIndexes($this->tablaFisica($tabla));
    }

    /** Consulta relaciones de clave foránea y adapta sus identificadores. */
    public function getForeignKeys($tabla)
    {
        return parent::getForeignKeys($this->tablaFisica($tabla));
    }

    /** Comprueba la existencia de una columna usando su nombre físico. */
    public function hasColumn($tabla, $columna)
    {
        return parent::hasColumn($tabla, $this->columnaEsquema($tabla, $columna));
    }

    /** Comprueba la existencia de las columnas solicitadas. */
    public function hasColumns($tabla, array $columnas)
    {
        return parent::hasColumns($tabla, array_map(fn ($columna) => $this->columnaEsquema($tabla, $columna), $columnas));
    }

    /** Consulta el tipo físico de una columna. */
    public function getColumnType($tabla, $columna, $definicionCompleta = false)
    {
        return parent::getColumnType($tabla, $this->columnaEsquema($tabla, $columna), $definicionCompleta);
    }
}
