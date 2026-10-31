<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_code_counters', function (Blueprint $table): void {
            $table->unsignedBigInteger('lock_version')->default(0);
        });
        Schema::table('branches', function (Blueprint $table): void {
            $table->timestamp('opening_stock_closed_at')->nullable();
            $table->foreignId('opening_stock_closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('financial_closed_through')->nullable();
        });
        Schema::table('inventories', function (Blueprint $table): void {
            $table->unsignedBigInteger('value_minor')->nullable();
            $table->unsignedInteger('quarantined_quantity')->default(0);
            $table->unsignedBigInteger('quarantined_value_minor')->default(0);
            $table->unsignedInteger('damaged_quantity')->default(0);
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->bigInteger('value_change_minor')->nullable();
            $table->string('stock_bucket', 20)->default('available');
            $table->date('effective_date')->nullable()->index();
        });
        Schema::table('opening_stock_entries', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
        });
        Schema::create('sales_controls', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
        });
        DB::table('sales_controls')->insert(['id' => 1]);
        Schema::create('financial_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name', 150);
            $table->string('type', 20);
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reference', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['branch_id', 'type', 'is_active']);
        });
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action', 80);
            $table->string('reason', 1000)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
        });
        Schema::create('sale_invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->char('request_hash', 64);
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('customer_name');
            $table->string('branch_name', 100);
            $table->string('cashier_name');
            $table->date('invoice_date');
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('approved_revision')->nullable();
            $table->unsignedInteger('total_quantity');
            $table->unsignedBigInteger('gross_minor');
            $table->unsignedBigInteger('line_discount_minor');
            $table->unsignedBigInteger('invoice_discount_minor');
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('cost_minor')->nullable();
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->unsignedBigInteger('returned_minor')->default(0);
            $table->unsignedBigInteger('released_minor')->default(0);
            $table->unsignedBigInteger('change_minor')->default(0);
            $table->json('payment_instructions')->nullable();
            $table->json('discount_instructions')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'invoice_date', 'status']);
            $table->index(['customer_id', 'invoice_date']);
        });
        Schema::create('sale_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->string('product_code', 50);
            $table->string('color_name', 100);
            $table->string('size_name', 100);
            $table->string('sku', 100);
            $table->string('image_path')->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('base_price_minor');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('gross_minor');
            $table->unsignedBigInteger('line_discount_minor');
            $table->unsignedBigInteger('invoice_discount_minor');
            $table->unsignedBigInteger('net_minor');
            $table->decimal('unit_cost', 18, 6)->nullable();
            $table->unsignedBigInteger('cost_minor')->nullable();
            $table->unsignedInteger('returned_quantity')->default(0);
            $table->string('price_reason', 500)->nullable();
            $table->json('discount_instructions')->nullable();
            $table->timestamps();
            $table->unique(['sale_invoice_id', 'product_variant_id']);
        });
        Schema::create('sale_returns', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->char('request_hash', 64);
            $table->foreignId('sale_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('approved');
            $table->string('kind', 20)->default('return');
            $table->date('transaction_date');
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('cost_minor');
            $table->unsignedBigInteger('debt_reduction_minor');
            $table->unsignedBigInteger('refund_minor');
            $table->unsignedBigInteger('credit_minor');
            $table->string('reason', 1000);
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'transaction_date']);
        });
        Schema::create('sale_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('condition', 20);
            $table->unsignedBigInteger('net_minor');
            $table->unsignedBigInteger('cost_minor');
            $table->unsignedBigInteger('recoverable_minor');
            $table->timestamps();
            $table->unique(['sale_return_id', 'sale_item_id']);
        });
        Schema::create('sale_exchanges', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->char('request_hash', 64);
            $table->foreignId('original_invoice_id')->constrained('sale_invoices')->restrictOnDelete();
            $table->foreignId('new_invoice_id')->nullable()->constrained('sale_invoices')->restrictOnDelete();
            $table->foreignId('sale_return_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('approved');
            $table->date('transaction_date');
            $table->string('reason', 1000);
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('customer_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->char('request_hash', 64);
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('kind', 20)->default('receipt');
            $table->string('status', 20)->default('approved');
            $table->string('origin', 20)->default('collection');
            $table->unsignedBigInteger('origin_id')->nullable();
            $table->date('transaction_date');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('change_minor')->default(0);
            $table->string('notes', 1000)->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'transaction_date']);
        });
        Schema::create('receipt_parts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('tendered_minor');
            $table->unsignedBigInteger('change_minor')->default(0);
            $table->string('reference', 150)->nullable();
            $table->timestamps();
        });
        Schema::create('receipt_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();
            $table->unique(['customer_receipt_id', 'sale_invoice_id']);
        });
        Schema::create('credit_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_return_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();
            $table->unique(['customer_receipt_id', 'sale_return_id']);
        });
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('event', 50);
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->date('transaction_date');
            $table->string('description', 500);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['source_type', 'source_id', 'event'], 'journal_source_event_unique');
            $table->index(['branch_id', 'transaction_date']);
        });
        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('sale_invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('sale_return_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('debit_minor')->default(0);
            $table->unsignedBigInteger('credit_minor')->default(0);
            $table->index(['financial_account_id', 'journal_entry_id']);
            $table->index(['customer_id', 'sale_invoice_id']);
        });
    }

    public function down(): void
    {
        foreach (['journal_lines', 'journal_entries', 'credit_usages', 'receipt_allocations', 'receipt_parts', 'customer_receipts', 'sale_exchanges', 'sale_return_items', 'sale_returns', 'sale_items', 'sale_invoices', 'audit_events', 'financial_accounts', 'sales_controls'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('opening_stock_entries', fn (Blueprint $table) => $table->dropColumn('version'));
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropColumn(['value_change_minor', 'stock_bucket', 'effective_date']));
        Schema::table('inventories', fn (Blueprint $table) => $table->dropColumn(['value_minor', 'quarantined_quantity', 'quarantined_value_minor', 'damaged_quantity']));
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('opening_stock_closed_by');
            $table->dropColumn(['opening_stock_closed_at', 'financial_closed_through']);
        });
        Schema::table('product_code_counters', fn (Blueprint $table) => $table->dropColumn('lock_version'));
    }
};
