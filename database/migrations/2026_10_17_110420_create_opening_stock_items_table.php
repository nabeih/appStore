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
        Schema::create('opening_stock_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('opening_stock_entry_id')
                ->constrained('opening_stock_entries')
                ->restrictOnDelete();

            $table->foreignId('product_variant_id')
                ->constrained('product_variants')
                ->restrictOnDelete();

            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 12, 2);

            $table->timestamps();

            $table->unique(
                ['opening_stock_entry_id', 'product_variant_id'],
                'opening_entry_variant_unique'
            );
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opening_stock_items');
    }
};
