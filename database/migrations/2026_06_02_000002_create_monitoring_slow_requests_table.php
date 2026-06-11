<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMonitoringSlowRequestsTable extends Migration
{
    public function up()
    {
        Schema::create('monitoring_slow_requests', function (Blueprint $table) {
            $table->id();

            $table->string('method', 10)->nullable();      // GET, POST, ...
            $table->text('url')->nullable();                // URL completa
            $table->string('route', 191)->nullable();       // nombre/acción de la ruta
            $table->unsignedInteger('duration_ms');         // duración total de la petición
            $table->unsignedSmallInteger('status')->nullable(); // código HTTP

            // Detalle de consultas dentro de esa petición.
            $table->unsignedInteger('query_count')->nullable();
            $table->unsignedInteger('query_time_ms')->nullable();   // tiempo total en BD
            $table->text('slowest_query')->nullable();              // la consulta más lenta
            $table->unsignedInteger('slowest_query_ms')->nullable();

            $table->unsignedBigInteger('user_id')->nullable();

            $table->timestamps();

            $table->index('created_at');
            $table->index('duration_ms');
        });
    }

    public function down()
    {
        Schema::dropIfExists('monitoring_slow_requests');
    }
}
