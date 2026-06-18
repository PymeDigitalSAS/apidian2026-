<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddSendEmailFieldsToDocumentPayrolls extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('document_payrolls', function (Blueprint $table) {
            $table->boolean('send_email_success')->default(false);
            $table->dateTime('send_email_date_time')->nullable();
            $table->string('send_email_error', 500)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('document_payrolls', function (Blueprint $table) {
            $table->dropColumn(['send_email_success', 'send_email_date_time', 'send_email_error']);
        });
    }
}
