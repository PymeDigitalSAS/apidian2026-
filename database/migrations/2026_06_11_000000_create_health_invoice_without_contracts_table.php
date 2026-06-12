<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class CreateHealthInvoiceWithoutContractsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * Crea el catalogo de motivos de FACTURA_SIN_CONTRATO (sector salud) y lo deja
     * poblado en la misma migracion, para que el cliente solo necesite ejecutar
     * `php artisan migrate` (sin seeders ni comandos adicionales).
     *
     * @return void
     */
    public function up()
    {
        Schema::create('health_invoice_without_contracts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->char('code');
            $table->timestamps();
        });

        DB::table('health_invoice_without_contracts')->insert([
            ['id' => 1, 'name' => 'ATENCION DE URGENCIAS', 'code' => '01', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'ATENCION A CARGO DE ADRES O DE ASEGURADORA SOAT, PLANES VOLUNTARIOS DE SALUD', 'code' => '02', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'ATENCION EN SALUD POR FALLOS DE TUTELA/ORDENES JUDICIALES', 'code' => '03', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'name' => 'ATENCION EN SALUD POR PORTABILIDAD O EN LOS CASOS DE ASIGNACION MASIVA DE AFILIADOS - ARTICULO 2.5.3.4.7.9 DEL DECRETO 780/2016', 'code' => '04', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 5, 'name' => 'ATENCION EN SALUD EN CASOS EXCEPCIONALES POR COTIZACIONES O AUTORIZACIONES SIN CONTRATO ATENCIONES ADICIONALES EXCEPCIONALES', 'code' => '05', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 6, 'name' => 'GESTION RECUPERACION DE ORGANOS PARA TRASPLANTE EN CUMPLIMIENTO LEY ESTATUTARIA 1751 DE 2015', 'code' => '06', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('health_invoice_without_contracts');
    }
}
