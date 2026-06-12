<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Filtro por fecha (mtime) de un respaldo: permite respaldar/eliminar solo los
 * archivos de un año o rango de fechas, en vez de todos. filter_from/filter_to
 * son timestamps unix (null = sin límite); filter_label es el texto a mostrar.
 */
class AddFilterToBackupsTable extends Migration
{
    public function up()
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->unsignedInteger('filter_from')->nullable()->after('name');
            $table->unsignedInteger('filter_to')->nullable()->after('filter_from');
            $table->string('filter_label')->nullable()->after('filter_to');
        });
    }

    public function down()
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn(['filter_from', 'filter_to', 'filter_label']);
        });
    }
}
