<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->timestamp('financial_posted_at')->nullable()->index();
            $table->json('payment_details')->nullable();
        });
        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->foreignId('financial_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('approved');
            $table->timestamp('financial_posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->index(['financial_account_id', 'payment_date']);
        });
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->char('request_hash', 64);
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->string('title', 200);
            $table->string('category', 100);
            $table->string('payee', 150)->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('transaction_date');
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference', 150)->nullable();
            $table->string('external_source', 80)->nullable();
            $table->string('external_reference', 150)->nullable();
            $table->unique(['external_source', 'external_reference']);
            $table->text('notes')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'transaction_date', 'status']);
        });
        Schema::create('expense_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->char('request_hash', 64);
            $table->foreignId('expense_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('approved');
            $table->date('transaction_date');
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference', 150)->nullable();
            $table->string('reason', 1000);
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'transaction_date']);
        });
        Schema::create('cash_counts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->char('request_hash', 64);
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('transaction_date');
            $table->bigInteger('expected_minor');
            $table->unsignedBigInteger('actual_minor');
            $table->bigInteger('difference_minor');
            $table->unsignedBigInteger('last_journal_line_id')->default(0);
            $table->string('reason', 1000);
            $table->timestamps();
            $table->index(['financial_account_id', 'transaction_date']);
        });
        Schema::create('treasury_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->char('request_hash', 64);
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('kind', 40);
            $table->string('status', 20)->default('approved');
            $table->foreignId('from_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('cash_count_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->date('transaction_date');
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference', 150)->nullable();
            $table->string('reason', 1000);
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'transaction_date', 'kind']);
        });
        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('purchase_invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained()->restrictOnDelete();
            $table->index(['supplier_id', 'purchase_invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->dropIndex(['supplier_id', 'purchase_invoice_id']);
            foreach (['supplier_id', 'purchase_invoice_id', 'expense_id'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
        });
        foreach (['treasury_documents', 'cash_counts', 'expense_payments', 'expenses'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->dropIndex(['financial_account_id', 'payment_date']);
            $table->dropConstrainedForeignId('financial_account_id');
            $table->dropColumn(['status', 'financial_posted_at', 'reversed_at']);
        });
        Schema::table('purchase_invoices', fn (Blueprint $table) => $table->dropColumn(['financial_posted_at', 'payment_details']));
    }
};
