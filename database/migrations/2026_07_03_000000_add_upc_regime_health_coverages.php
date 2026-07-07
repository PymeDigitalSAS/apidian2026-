<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddUpcRegimeHealthCoverages extends Migration
{
    /**
     * Run the migrations.
     *
     * Resolucion 948 de 2026 (MinSalud): la cobertura 01 "Plan de beneficios en salud
     * financiado con UPC" queda inactiva y se reemplaza por la 16 (Regimen Contributivo)
     * y la 17 (Regimen Subsidiado), segun la Resolucion 2765 de 2025.
     * La fila 01 se conserva para no romper documentos historicos que la referencian;
     * solo se deja de ofrecer en el catalogo y se rechaza en documentos nuevos.
     *
     * @return void
     */
    public function up()
    {
        $rows = [
            ['id' => 16, 'name' => 'Plan de beneficios en salud financiado con UPC Régimen Contributivo', 'code' => '16', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 17, 'name' => 'Plan de beneficios en salud financiado con UPC Régimen Subsidiado', 'code' => '17', 'created_at' => now(), 'updated_at' => now()],
        ];

        foreach ($rows as $row) {
            if (!DB::table('health_coverages')->where('id', $row['id'])->exists()) {
                DB::table('health_coverages')->insert($row);
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::table('health_coverages')->whereIn('id', [16, 17])->delete();
    }
}
