<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddDocumentTemplatesToCompanies extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->integer('eqdocs_template')->nullable()->after('graphic_representation_template');
            $table->integer('support_document_template')->nullable()->after('eqdocs_template');
            $table->integer('payroll_template')->nullable()->after('support_document_template');
        });

        // Inicializar cada tipo con la plantilla ya configurada por la empresa,
        // para que el comportamiento actual no cambie. graphic_representation_template
        // queda como la plantilla de facturas y notas credito/debito (y fallback
        // cuando el tipo esta en NULL).
        DB::table('companies')->update([
            'eqdocs_template' => DB::raw('graphic_representation_template'),
            'support_document_template' => DB::raw('graphic_representation_template'),
            // nomina solo tiene plantillas 2 y 8 (ver createPDFPayroll)
            'payroll_template' => DB::raw('CASE WHEN graphic_representation_template IN (2, 8) THEN graphic_representation_template ELSE 2 END'),
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'eqdocs_template',
                'support_document_template',
                'payroll_template',
            ]);
        });
    }
}
