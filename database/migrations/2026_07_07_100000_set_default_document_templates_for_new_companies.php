<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

class SetDefaultDocumentTemplatesForNewCompanies extends Migration
{
    /**
     * Run the migrations.
     *
     * Fija los DEFAULT de las columnas de plantilla para EMPRESAS NUEVAS.
     * Cambiar el DEFAULT es metadata: NO modifica las filas existentes (las
     * empresas ya creadas conservan el valor copiado de facturas por la
     * migración 2026_07_03). Facturas -> 12; POS/soporte/nómina -> 2.
     *
     * @return void
     */
    public function up()
    {
        DB::statement('ALTER TABLE `companies` ALTER COLUMN `graphic_representation_template` SET DEFAULT 12');
        DB::statement('ALTER TABLE `companies` ALTER COLUMN `eqdocs_template` SET DEFAULT 2');
        DB::statement('ALTER TABLE `companies` ALTER COLUMN `support_document_template` SET DEFAULT 2');
        DB::statement('ALTER TABLE `companies` ALTER COLUMN `payroll_template` SET DEFAULT 2');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Facturas vuelve a su default original (2); las columnas por tipo
        // vuelven a quedar sin default (nullable).
        DB::statement('ALTER TABLE `companies` ALTER COLUMN `graphic_representation_template` SET DEFAULT 2');
        DB::statement('ALTER TABLE `companies` ALTER COLUMN `eqdocs_template` DROP DEFAULT');
        DB::statement('ALTER TABLE `companies` ALTER COLUMN `support_document_template` DROP DEFAULT');
        DB::statement('ALTER TABLE `companies` ALTER COLUMN `payroll_template` DROP DEFAULT');
    }
}
