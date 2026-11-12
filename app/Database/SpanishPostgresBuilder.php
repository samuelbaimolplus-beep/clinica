<?php

/** Traduce introspección del esquema para que migraciones y modelos mantengan convenciones Laravel. */

namespace App\Database;

use Illuminate\Database\Schema\PostgresBuilder;

class SpanishPostgresBuilder extends PostgresBuilder
{
    /** Resuelve el nombre físico de una tabla para consultar el catálogo de PostgreSQL. */
    private function physicalTable(string $table): string
    {
        if (! $this->connection->hasSpanishSchema()) {
            return $table;
        }
        $parts = explode('.', $table);
        $last = array_key_last($parts);
        $parts[$last] = config('database_names.tables')[$parts[$last]] ?? $parts[$last];

        return implode('.', $parts);
    }

    /** Distingue nombres convencionales de Laravel y nombres físicos del esquema. */
    private function usesApplicationNames(string $table): bool
    {
        $parts = explode('.', $table);

        return $this->connection->hasSpanishSchema() && isset(config('database_names.tables')[end($parts)]);
    }

    /** Resuelve el nombre físico de una columna durante la introspección. */
    private function schemaColumn(string $table, string $column): string
    {
        if (! $this->connection->hasSpanishSchema()) {
            return $column;
        }
        $names = $this->usesApplicationNames($table)
            ? array_flip(config('database_names.columns'))
            : config('database_names.columns');

        return $names[$column] ?? $column;
    }

    /** Comprueba la existencia de una tabla usando su nombre físico. */
    public function hasTable($table)
    {
        return parent::hasTable($this->physicalTable($table));
    }

    /** Consulta las columnas de la tabla y adapta sus identificadores. */
    public function getColumns($table)
    {
        $columns = parent::getColumns($this->physicalTable($table));
        if ($this->usesApplicationNames($table)) {
            $names = array_flip(config('database_names.columns'));
            foreach ($columns as &$column) {
                $column['name'] = $names[$column['name']] ?? $column['name'];
            }
        }

        return $columns;
    }

    /** Consulta índices de una tabla para las migraciones e inspección del esquema. */
    public function getIndexes($table)
    {
        return parent::getIndexes($this->physicalTable($table));
    }

    /** Consulta relaciones de clave foránea y adapta sus identificadores. */
    public function getForeignKeys($table)
    {
        return parent::getForeignKeys($this->physicalTable($table));
    }

    /** Comprueba la existencia de una columna usando su nombre físico. */
    public function hasColumn($table, $column)
    {
        return parent::hasColumn($table, $this->schemaColumn($table, $column));
    }

    /** Comprueba la existencia de las columnas solicitadas. */
    public function hasColumns($table, array $columns)
    {
        return parent::hasColumns($table, array_map(fn ($column) => $this->schemaColumn($table, $column), $columns));
    }

    /** Consulta el tipo físico de una columna. */
    public function getColumnType($table, $column, $fullDefinition = false)
    {
        return parent::getColumnType($table, $this->schemaColumn($table, $column), $fullDefinition);
    }
}
