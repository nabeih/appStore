<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained();
            $table->foreignId('color_id')->constrained();
            $table->foreignId('size_id')->constrained();
            $table->string('sku', 50)->unique();


            $table->decimal('selling_price', 12, 2);
            $table->decimal('minimum_selling_price', 12, 2)->nullable();

            $table->boolean('is_active')->default(true);
            // منع تكرار نفس اللون والمقاس لنفس المنتج
            $table->unique(
                ['product_id', 'color_id', 'size_id'],
                'product_color_size_unique'
            );
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
