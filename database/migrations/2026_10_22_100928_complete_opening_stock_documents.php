<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opening_stock_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->change();
            $table->date('opening_date')->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->unsignedBigInteger('total_quantity')->default(0);
            $table->decimal('total_cost', 18, 2)->default(0);
            $table->index(['branch_id', 'opening_date']);
        });

        Schema::table('opening_stock_items', function (Blueprint $table) {
            $table->string('product_code', 50)->nullable();
            $table->string('product_name', 200)->nullable();
            $table->string('category_name')->nullable();
            $table->string('color_name')->nullable();
            $table->string('size_name')->nullable();
            $table->string('sku', 100)->nullable();
            $table->decimal('selling_price', 12, 2)->nullable();
            $table->decimal('line_total', 18, 2)->default(0);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('sku', 100)->change();
        });
    }

    public function down(): void
    {
        // Keep nullable actors and widened SKUs so rollback cannot invalidate existing records.
        Schema::table('opening_stock_items', function (Blueprint $table) {
            $table->dropColumn(['product_code', 'product_name', 'category_name', 'color_name', 'size_name', 'sku', 'selling_price', 'line_total']);
        });
        Schema::table('opening_stock_entries', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'opening_date']);
            $table->dropColumn(['opening_date', 'request_hash', 'total_quantity', 'total_cost']);
        });
    }
};
