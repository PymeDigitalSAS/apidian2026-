<?php

namespace App\Helpers;

use DB;

class RegularizeDataHelper
{
    
    /**
     * Regularizar data de las tablas con errores
     * Verifica si tiene registros (seeders con errores)
     * Elimina la data
     * Regulariza con la data actualizada del .csv
     *
     * @param  string $table_name
     * @param  array $table_columns
     * @return void
     */
    public static function regularizeDataFromTable($table_name, $table_columns)
    {

        $exist_records = self::countRecords($table_name);

        // si hay registros se eliminan para ejecutar los csv corregidos
        if($exist_records > 0)
        {
            self::deleteRecords($table_name);
            self::insertDataFromSeeder($table_name, $table_columns);
        }

    }


    public static function insertDataFromSeeder($table_name, $table_columns)
    {
        $prefix = 'csv';
        $key = $table_name;

        $path_file = str_replace('\\', '/', public_path($prefix.DIRECTORY_SEPARATOR."{$key}.{$prefix}"));

        self::loadCsvFile($key, $table_columns['columns'], $path_file);
    }


    /**
     * Carga un archivo .csv (separado por tabuladores) en una tabla.
     * Intenta LOAD DATA LOCAL INFILE y, si el servidor MySQL/MariaDB
     * lo tiene deshabilitado (local_infile=OFF, comun en hostings
     * compartidos), inserta los registros desde PHP.
     *
     * @param  string $table
     * @param  string $columns  ej: 'id, name, code, @created_at, @updated_at'
     * @param  string $path_file
     * @param  bool   $ignore  usar INSERT IGNORE (omitir duplicados)
     * @return void
     */
    public static function loadCsvFile($table, $columns, $path_file, $ignore = false)
    {
        // LOAD DATA es exclusivo de MySQL/MariaDB; en otros motores
        // (PostgreSQL) se inserta directo desde PHP sin intentar el exec,
        // para no abortar la transaccion activa con un error de sintaxis.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return self::insertCsvWithPhp($table, $columns, $path_file, $ignore);
        }

        try {
            $keyword = $ignore ? ' IGNORE' : '';
            DB::connection()
                ->getpdo()
                ->exec("LOAD DATA LOCAL INFILE '".$path_file."'{$keyword} INTO TABLE $table({$columns}) SET created_at = NOW(), updated_at = NOW()");
        } catch (\PDOException $e) {
            self::insertCsvWithPhp($table, $columns, $path_file, $ignore);
        }
    }


    public static function insertCsvWithPhp($table, $columns, $path_file, $ignore = false)
    {
        // las columnas @var del LOAD DATA son placeholders que se
        // resuelven con el SET (created_at/updated_at), se omiten aqui
        $fields = [];
        foreach (explode(',', $columns) as $column) {
            $column = trim($column);
            $fields[] = (strpos($column, '@') === 0) ? null : $column;
        }

        $handle = fopen($path_file, 'r');
        if ($handle === false) {
            return;
        }

        $now = now();
        $rows = [];

        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }

            $data = explode("\t", $line);
            $row = [];
            foreach ($fields as $i => $field) {
                if ($field === null) {
                    continue;
                }
                $value = isset($data[$i]) ? $data[$i] : null;
                // \N es la representacion de NULL en LOAD DATA
                $row[$field] = ($value === '\N') ? null : $value;
            }
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $rows[] = $row;

            if (count($rows) >= 500) {
                self::insertRows($table, $rows, $ignore);
                $rows = [];
            }
        }
        fclose($handle);

        if (count($rows) > 0) {
            self::insertRows($table, $rows, $ignore);
        }

        self::syncPgSequence($table);
    }


    /**
     * En PostgreSQL la secuencia del id NO avanza cuando se insertan ids
     * explicitos (los CSV traen la columna id), a diferencia del
     * AUTO_INCREMENT de MySQL. Sin esto, el siguiente INSERT sin id
     * intenta usar id=1 y falla con "duplicate key".
     */
    protected static function syncPgSequence($table)
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $has_id = DB::selectOne(
            "SELECT 1 AS x FROM information_schema.columns WHERE table_name = ? AND column_name = 'id'",
            [$table]
        );
        if (!$has_id) {
            return;
        }

        $seq = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS seq", [$table]);
        if ($seq && $seq->seq) {
            DB::statement("SELECT setval('{$seq->seq}', (SELECT COALESCE(MAX(id), 0) + 1 FROM {$table}), false)");
        }
    }


    protected static function insertRows($table, $rows, $ignore)
    {
        if ($ignore) {
            DB::table($table)->insertOrIgnore($rows);
        } else {
            DB::table($table)->insert($rows);
        }
    }


    public static function deleteRecords($table)
    {
        DB::table($table)->delete();
    }


    public static function countRecords($table)
    {
        return DB::table($table)->count();
    }

}
