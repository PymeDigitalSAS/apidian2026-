<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * Habilita la extension unaccent en PostgreSQL, necesaria para que las
 * busquedas por nombre (municipios, departamentos, paises) ignoren los
 * acentos como lo hace la collation *_ci de MySQL. En MySQL/MariaDB esta
 * migracion no hace nada.
 *
 * Requiere que el usuario de la BD pueda crear extensiones (el usuario que
 * crea la imagen oficial de Docker puede; en un Postgres administrado tipo
 * RDS la extension esta en la lista blanca estandar).
 */
class EnableUnaccentExtensionPgsql extends Migration
{
    public function up()
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    public function down()
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP EXTENSION IF EXISTS unaccent');
    }
}
