<?php

namespace App\Database;

use Illuminate\Database\Schema\PostgresBuilder;

class SpanishPostgresBuilder extends PostgresBuilder
{
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

    private function usesApplicationNames(string $table): bool
    {
        $parts = explode('.', $table);

        return $this->connection->hasSpanishSchema() && isset(config('database_names.tables')[end($parts)]);
    }

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

    public function hasTable($table)
    {
        return parent::hasTable($this->physicalTable($table));
    }

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

    public function getIndexes($table)
    {
        return parent::getIndexes($this->physicalTable($table));
    }

    public function getForeignKeys($table)
    {
        return parent::getForeignKeys($this->physicalTable($table));
    }

    public function hasColumn($table, $column)
    {
        return parent::hasColumn($table, $this->schemaColumn($table, $column));
    }

    public function hasColumns($table, array $columns)
    {
        return parent::hasColumns($table, array_map(fn ($column) => $this->schemaColumn($table, $column), $columns));
    }

    public function getColumnType($table, $column, $fullDefinition = false)
    {
        return parent::getColumnType($table, $this->schemaColumn($table, $column), $fullDefinition);
    }
}
