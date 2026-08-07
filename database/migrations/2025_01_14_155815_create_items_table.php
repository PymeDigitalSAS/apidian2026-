<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateItemsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 500)->nullable();
            $table->string('second_name', 500)->nullable();
            $table->string('description', 500)->nullable();
            $table->char('item_type_id', 2);
            $table->string('internal_id', 100)->nullable();
            $table->date('date_of_due')->nullable();
            $table->decimal('sale_unit_price', 16, 6);
            $table->decimal('purchase_unit_price', 16, 6)->default(0.000000);
            $table->decimal('commission_amount', 8, 2)->nullable();
            $table->string('commission_type', 255)->nullable();
            $table->decimal('amount_plastic_bag_taxes', 6, 2)->default(0.10);
            $table->boolean('calculate_quantity')->default(false);
            $table->decimal('sale_unit_price_set', 16, 6)->nullable();
            $table->boolean('is_set')->default(false);
            $table->string('model', 255)->nullable();
            $table->string('image', 255)->default('imagen-no-disponible.jpg');
            $table->string('image_medium', 255)->default('imagen-no-disponible.jpg');
            $table->string('image_small', 255)->default('imagen-no-disponible.jpg');
            $table->decimal('stock', 16, 4)->default(0.0000);
            $table->decimal('stock_min', 12, 2)->default(0.00);
            $table->string('lot_code', 255)->nullable();
            $table->boolean('lots_enabled')->default(false);
            $table->boolean('series_enabled')->default(false);
            $table->decimal('percentage_of_profit', 12, 2)->default(0.00);
            $table->boolean('has_perception')->default(false);
            $table->decimal('percentage_perception', 12, 2)->nullable();
            $table->json('attributes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->boolean('status')->default(true);
            $table->boolean('apply_store')->default(false);

            $table->unique('name');
            $table->unique('internal_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('items');
    }
}
