<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Alinea identification_number de document_payrolls y received_documents a
 * string(25), como ya lo son companies.identification_number (desde 2019) y
 * documents.identification_number (migracion 2020_07_14).
 *
 * Motivo: estas dos columnas quedaron bigInteger y ~20 consultas les pasan
 * el identification_number (varchar) de la empresa. MySQL coacciona en
 * silencio; PostgreSQL lanza 22P02 si el NIT no es puramente numerico
 * (documentos extranjeros, espacios, guiones). Ademas un bigint nunca pudo
 * almacenar esos identificadores alfanumericos.
 */
class ChangeIdentificationNumberToStringPayrollReceived extends Migration
{
    public function up()
    {
        Schema::table('document_payrolls', function (Blueprint $table) {
            $table->string('identification_number', 25)->nullable()->change();
        });

        Schema::table('received_documents', function (Blueprint $table) {
            $table->string('identification_number', 25)->change();
        });
    }

    public function down()
    {
        Schema::table('document_payrolls', function (Blueprint $table) {
            $table->bigInteger('identification_number')->nullable()->change();
        });

        Schema::table('received_documents', function (Blueprint $table) {
            $table->bigInteger('identification_number')->change();
        });
    }
}
