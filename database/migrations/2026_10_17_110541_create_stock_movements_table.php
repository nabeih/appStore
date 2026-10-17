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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();


            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->foreignId('product_variant_id')
                ->constrained('product_variants')
                ->restrictOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('movement_type', 30);

            $table->integer('quantity_change');
            $table->decimal('unit_cost', 12, 2)->nullable();

            $table->morphs('source');

            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['source_type', 'source_id', 'movement_type', 'branch_id'],
                'stock_movement_source_unique'
            );

            $table->index(
                ['branch_id', 'product_variant_id', 'created_at'],
                'stock_movement_lookup_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
