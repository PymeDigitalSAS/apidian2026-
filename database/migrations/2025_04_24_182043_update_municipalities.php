<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class UpdateMunicipalities extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Parche para instalaciones existentes: en instalaciones nuevas
        // departments aun esta vacio (lo llena el seeder) y Guamal ya viene
        // en el CSV. El INSERT IGNORE de MySQL saltaba la FK huerfana en
        // silencio; en PostgreSQL hay que comprobarla explicitamente.
        if (!DB::table('departments')->where('id', 21)->exists()) {
            return;
        }

        DB::table('municipalities')->insertOrIgnore([
            'department_id' => 21,
            'name' => 'Guamal',
            'code' => '50318',
            'codefacturador' => 13242
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::table('municipalities')
            ->where('department_id', 21)
            ->where('code', '50318')
            ->delete();
    }
}
