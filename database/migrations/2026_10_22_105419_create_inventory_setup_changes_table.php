<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_setup_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inventory_id')->index();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('opening_stock_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('opening_stock_item_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation', 20);
            $table->string('reason', 500);
            $table->json('before_state');
            $table->json('after_state')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_setup_changes');
    }
};
