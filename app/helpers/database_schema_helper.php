<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

if (!function_exists('database_schema_rows')) {
    function database_schema_rows($db, $tableName)
    {
        $database = database_config()['main']['database'] ?? '';
        if (!is_string($database) || $database === '') {
            throw new RuntimeException('The active database name is not configured.');
        }

        return $db->table('information_schema.columns')
            ->select('column_name, column_type, is_nullable, column_key, column_default, extra')
            ->where('table_schema', $database)
            ->where('table_name', $tableName)
            ->order_by('ordinal_position', 'ASC')
            ->get_all();
    }
}

if (!function_exists('get_table_column_metadata')) {
    function get_table_column_metadata($db, $tableName)
    {
        $rows = database_schema_rows($db, $tableName);

        return array_map(static function ($row) {
            return [
                'Field' => $row['column_name'],
                'Type' => $row['column_type'],
                'Null' => $row['is_nullable'],
                'Key' => $row['column_key'],
                'Default' => $row['column_default'],
                'Extra' => $row['extra'],
            ];
        }, $rows);
    }
}

if (!function_exists('get_table_column_names')) {
    function get_table_column_names($db, $tableName)
    {
        return array_column(database_schema_rows($db, $tableName), 'column_name');
    }
}

if (!function_exists('database_table_exists')) {
    function database_table_exists($db, $tableName)
    {
        $database = database_config()['main']['database'] ?? '';
        if (!is_string($database) || $database === '') {
            throw new RuntimeException('The active database name is not configured.');
        }

        return $db->table('information_schema.tables')
            ->select('table_name')
            ->where('table_schema', $database)
            ->where('table_name', $tableName)
            ->count() > 0;
    }
}
