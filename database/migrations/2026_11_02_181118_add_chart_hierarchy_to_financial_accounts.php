<?php

use App\Actions\Finance\ChartOfAccounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('financial_accounts')->get() as $account) {
            if (ChartOfAccounts::classification($account->code, $account->type) === null) {
                throw new RuntimeException('An unclassified existing financial account requires review before this migration.');
            }
        }
        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->foreignId('parent_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->string('account_group', 20)->nullable()->index();
            $table->string('normal_balance', 6)->nullable();
            $table->boolean('is_posting')->default(true);
            $table->boolean('is_protected')->default(true);
        });
        foreach (DB::table('financial_accounts')->get() as $account) {
            DB::table('financial_accounts')->where('id', $account->id)->update(ChartOfAccounts::classification($account->code, $account->type));
        }
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER chart_journal_posting_guard BEFORE INSERT ON journal_lines FOR EACH ROW
                BEGIN
                    IF NOT EXISTS (SELECT 1 FROM financial_accounts WHERE id = NEW.financial_account_id AND is_posting = 1) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posting requires a posting account';
                    END IF;
                END");
            DB::unprepared("CREATE TRIGGER chart_account_history_guard BEFORE UPDATE ON financial_accounts FOR EACH ROW
                BEGIN
                    IF (OLD.is_protected = 1 OR EXISTS (SELECT 1 FROM journal_lines WHERE financial_account_id = OLD.id))
                       AND (NOT (NEW.code <=> OLD.code) OR NOT (NEW.type <=> OLD.type)
                         OR NOT (NEW.account_group <=> OLD.account_group) OR NOT (NEW.normal_balance <=> OLD.normal_balance)
                         OR NOT (NEW.branch_id <=> OLD.branch_id) OR NOT (NEW.parent_id <=> OLD.parent_id)
                         OR NEW.is_posting <> OLD.is_posting OR NEW.is_protected <> OLD.is_protected OR NEW.is_active <> OLD.is_active) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Protected or historical account structure is immutable';
                    END IF;
                END");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Chart hierarchy and accounting safeguards require a separately reviewed rollback; no automatic deletion.');
    }
};
