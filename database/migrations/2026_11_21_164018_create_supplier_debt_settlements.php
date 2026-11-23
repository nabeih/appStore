<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' && ! (DB::getDriverName() === 'sqlite' && app()->environment('testing'))) {
            throw new LogicException('Supplier debt posting requires MySQL with enforced accounting safeguards.');
        }
        if (DB::getDriverName() === 'mysql' && (str_contains(strtolower(DB::selectOne('SELECT VERSION() AS version')->version), 'mariadb')
            || version_compare(DB::selectOne('SELECT VERSION() AS version')->version, '8.0.16', '<'))) {
            throw new LogicException('Supplier debt safeguards require MySQL 8.0.16+.');
        }
        Schema::create('supplier_debt_settlements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->char('request_hash', 64);
            $table->char('origin_fingerprint', 64)->unique();
            $table->char('approval_scope', 64)->nullable()->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->date('original_date');
            $table->date('tracking_started_on');
            $table->date('posting_date');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->string('reference', 150);
            $table->text('notes');
            $table->string('evidence_path', 500);
            $table->string('evidence_name');
            $table->char('evidence_sha256', 64);
            $table->timestamp('financial_posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->index(['supplier_id', 'branch_id', 'status']);
        });
        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('purchase_invoice_id')->nullable()->change();
            $table->foreignId('supplier_debt_settlement_id')->nullable()->constrained()->restrictOnDelete();
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payment_exactly_one_source CHECK ((purchase_invoice_id IS NULL) <> (supplier_debt_settlement_id IS NULL)) ENFORCED');
            DB::statement("ALTER TABLE supplier_debt_settlements ADD CONSTRAINT supplier_debt_amount_check CHECK (amount_minor > 0 AND paid_minor <= amount_minor AND original_date < tracking_started_on AND status IN ('draft', 'approved', 'reversed') AND ((status = 'approved' AND approval_scope IS NOT NULL AND approved_by IS NOT NULL AND financial_posted_at IS NOT NULL) OR (status <> 'approved' AND approval_scope IS NULL AND paid_minor = 0))) ENFORCED");
            $immutable = ['supplier_id', 'branch_id', 'created_by', 'client_uuid', 'request_hash', 'origin_fingerprint', 'original_date', 'tracking_started_on', 'posting_date', 'amount_minor', 'reference', 'notes', 'evidence_path', 'evidence_name', 'evidence_sha256', 'approved_by', 'financial_posted_at'];
            $changed = implode(' OR ', array_map(fn (string $column): string => "NOT (NEW.`{$column}` <=> OLD.`{$column}`)", $immutable));
            DB::unprepared("CREATE TRIGGER supplier_debt_history_update BEFORE UPDATE ON supplier_debt_settlements FOR EACH ROW BEGIN
                IF OLD.status <> 'draft' AND ({$changed} OR (OLD.status = 'reversed' AND NEW.status <> 'reversed') OR NEW.status = 'draft') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approved supplier debt history is immutable';
                END IF;
            END");
            DB::unprepared("CREATE TRIGGER supplier_debt_history_delete BEFORE DELETE ON supplier_debt_settlements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Supplier debt documents must be retained; use formal reversal'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Supplier debt history and payment sources require a separately reviewed rollback; automatic removal is prohibited.');
    }
};
