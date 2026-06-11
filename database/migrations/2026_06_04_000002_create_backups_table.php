<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Respaldos manuales generados desde el panel de Backups. Cada registro es un
 * ZIP con los archivos de una empresa (o de todas) que el admin puede descargar
 * para luego liberar espacio borrando los archivos reales.
 */
class CreateBackupsTable extends Migration
{
    public function up()
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            // 'all' = todas las empresas; 'company' = una empresa específica.
            $table->string('scope', 20)->default('company');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('nit')->nullable();
            $table->string('name')->nullable();
            // pending | processing | done | failed
            $table->string('status', 20)->default('pending')->index();
            $table->string('disk_path')->nullable();   // relativo a storage/app
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('files_count')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->string('requested_by')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('backups');
    }
}
