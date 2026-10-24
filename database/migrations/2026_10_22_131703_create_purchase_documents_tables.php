<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->char('request_hash', 64);
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('supplier_name');
            $table->string('branch_name', 150);
            $table->string('supplier_invoice_number', 100)->nullable();
            $table->date('invoice_date');
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('approved_revision')->nullable();
            $table->unsignedInteger('total_quantity');
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->unsignedBigInteger('initial_payment_minor')->default(0);
            $table->string('payment_method', 20)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['supplier_id', 'status', 'invoice_date']);
            $table->index(['branch_id', 'status', 'invoice_date']);
        });
        Schema::create('purchase_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->string('product_code', 50);
            $table->string('category_name', 100);
            $table->string('color_name', 100);
            $table->string('size_name', 100);
            $table->string('sku', 100);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_cost_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->timestamps();
            $table->unique(['purchase_invoice_id', 'product_variant_id'], 'purchase_item_variant_unique');
        });
        Schema::create('supplier_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->char('request_hash', 64);
            $table->foreignId('purchase_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('method', 20);
            $table->date('payment_date');
            $table->string('reference', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->index(['supplier_id', 'payment_date']);
            $table->index(['branch_id', 'method', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('purchase_invoice_items');
        Schema::dropIfExists('purchase_invoices');
    }
};
