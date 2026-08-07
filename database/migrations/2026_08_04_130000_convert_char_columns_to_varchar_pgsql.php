<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * Convierte TODAS las columnas char(n) a varchar(n) en PostgreSQL,
 * recortando los espacios de relleno ya almacenados.
 *
 * Motivo: en MySQL CHAR recorta los espacios finales al leer, pero en
 * PostgreSQL char(n) devuelve el valor relleno hasta n. Eso contamina
 * concatenaciones y nombres de archivo (p. ej. el ZIP enviado a la DIAN
 * salia como "fv<189 espacios>NIT..." y la DIAN rechazaba con ZB01
 * "Fallo en el esquema XML").
 *
 * En MySQL/MariaDB no hace nada. Los indices y FKs sobreviven al cambio
 * de tipo. Regla para nuevas migraciones: usar $table->string(), no char().
 */
class ConvertCharColumnsToVarcharPgsql extends Migration
{
    public function up()
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $columns = DB::select("
            SELECT table_name, column_name, character_maximum_length
            FROM information_schema.columns
            WHERE table_schema = 'public' AND data_type = 'character'
            ORDER BY table_name, column_name
        ");

        foreach ($columns as $c) {
            $len = (int) ($c->character_maximum_length ?: 191);
            DB::statement(sprintf(
                'ALTER TABLE "%s" ALTER COLUMN "%s" TYPE varchar(%d) USING TRIM(TRAILING \' \' FROM "%s")',
                $c->table_name,
                $c->column_name,
                $len,
                $c->column_name
            ));
        }
    }

    public function down()
    {
        // Sin reversa: volver a char(n) reintroduciria el problema del
        // relleno y no aporta nada.
    }
}
