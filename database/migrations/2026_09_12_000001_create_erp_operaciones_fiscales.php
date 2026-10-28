<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Diario idempotente independiente; no modifica tablas fiscales existentes. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('erp_operaciones_fiscales', function (Blueprint $tabla) {
            $tabla->uuid('id')->primary();
            // Relación lógica: instalaciones previas difieren en el tipo de companies.id.
            $tabla->unsignedBigInteger('empresa_id');
            $tabla->string('ambiente', 20);
            $tabla->string('clave_idempotencia', 100);
            $tabla->char('huella', 64);
            $tabla->string('tipo_documento', 30);
            $tabla->string('prefijo', 20);
            $tabla->unsignedBigInteger('numero');
            $tabla->unsignedInteger('revision')->default(1);
            $tabla->string('estado', 30);
            $tabla->longText('carga');
            $tabla->longText('respuesta')->nullable();
            $tabla->unsignedSmallInteger('codigo_http')->nullable();
            $tabla->dateTime('creado_en');
            $tabla->dateTime('actualizado_en');
            $tabla->unique(['empresa_id', 'ambiente', 'clave_idempotencia'], 'erp_idempotencia');
            $tabla->unique(['empresa_id', 'ambiente', 'tipo_documento', 'prefijo', 'numero', 'revision'], 'erp_identidad_fiscal');
            $tabla->index(['estado', 'actualizado_en'], 'erp_conciliacion');
        });
        Schema::create('erp_consultas_fiscales', function (Blueprint $tabla) {
            $tabla->bigIncrements('id');
            $tabla->uuid('operacion_id');
            $tabla->longText('respuesta');
            $tabla->dateTime('creado_en');
            $tabla->foreign('operacion_id')->references('id')->on('erp_operaciones_fiscales')->onDelete('restrict');
        });
        Schema::create('erp_entregas_fiscales', function (Blueprint $tabla) {
            $tabla->uuid('id')->primary();
            $tabla->uuid('operacion_id');
            $tabla->string('estado', 30);
            $tabla->string('destinatario', 254);
            $tabla->dateTime('creado_en');
            $tabla->dateTime('actualizado_en');
            $tabla->foreign('operacion_id')->references('id')->on('erp_operaciones_fiscales')->onDelete('restrict');
        });
    }
    public function down(): void
    {
        // El historial fiscal no se elimina mediante un rollback automático.
        throw new RuntimeException('Migración fiscal irreversible: conserve erp_operaciones_fiscales.');
    }
};
