<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMonitoringSnapshotsTable extends Migration
{
    public function up()
    {
        Schema::create('monitoring_snapshots', function (Blueprint $table) {
            $table->id();

            // CPU / carga del sistema
            $table->float('cpu_percent')->nullable();
            $table->float('load_1')->nullable();
            $table->float('load_5')->nullable();
            $table->float('load_15')->nullable();
            $table->unsignedSmallInteger('cpu_cores')->nullable();

            // Memoria (en bytes)
            $table->unsignedBigInteger('mem_total')->nullable();
            $table->unsignedBigInteger('mem_used')->nullable();
            $table->float('mem_percent')->nullable();

            // Almacenamiento / disco (en bytes)
            $table->unsignedBigInteger('disk_total')->nullable();
            $table->unsignedBigInteger('disk_used')->nullable();
            $table->float('disk_percent')->nullable();

            // Base de datos
            $table->unsignedInteger('db_connections')->nullable();
            $table->unsignedInteger('db_threads_running')->nullable();
            $table->unsignedBigInteger('db_slow_queries')->nullable();

            // Detalle de qué está consumiendo
            $table->json('top_queries')->nullable();    // consultas SQL activas
            $table->json('top_processes')->nullable();   // procesos del SO por CPU/mem

            // Contexto del entorno
            $table->string('hostname', 191)->nullable();
            $table->string('environment', 30)->nullable(); // docker / apache / cli / etc.

            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('monitoring_snapshots');
    }
}
