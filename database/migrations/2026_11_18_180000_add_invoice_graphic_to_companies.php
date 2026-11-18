<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddInvoiceGraphicToCompanies extends Migration
{
    public function up()
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('invoice_graphic')->nullable()->after('graphic_representation_template');
        });
    }

    public function down()
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('invoice_graphic');
        });
    }
}
