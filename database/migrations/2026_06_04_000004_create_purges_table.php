<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Liberaciones de espacio (borrado de archivos reales) ejecutadas en segundo
 * plano. Cada registro es una operación destructiva sobre los documentos de una
 * empresa (o de todas), opcionalmente filtrada por fecha. Se procesa en un Job
 * para no bloquear la petición web ni dar timeout con empresas grandes.
 */
class CreatePurgesTable extends Migration
{
    public function up()
    {
        Schema::create('purges', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20)->default('company'); // all | company
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('nit')->nullable();
            $table->string('name')->nullable();
            $table->unsignedInteger('filter_from')->nullable();
            $table->unsignedInteger('filter_to')->nullable();
            $table->string('filter_label')->nullable();
            // pending | processing | done | failed
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('deleted_files')->nullable();
            $table->unsignedBigInteger('freed_bytes')->nullable();
            $table->text('error')->nullable();
            $table->string('requested_by')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('purges');
    }
}
