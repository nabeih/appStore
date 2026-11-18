<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->installGuard('chart_account_history_guard_v2', 'financial_accounts', 'UPDATE', "BEGIN
            IF (OLD.is_protected = 1 OR EXISTS (SELECT 1 FROM journal_lines WHERE financial_account_id = OLD.id))
               AND (NOT (NEW.code <=> OLD.code) OR NOT (NEW.type <=> OLD.type)
                 OR NOT (NEW.account_group <=> OLD.account_group) OR NOT (NEW.normal_balance <=> OLD.normal_balance)
                 OR NOT (NEW.branch_id <=> OLD.branch_id) OR NOT (NEW.parent_id <=> OLD.parent_id)
                 OR NEW.is_posting <> OLD.is_posting OR NEW.is_protected <> OLD.is_protected) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Protected or historical account structure is immutable';
            END IF;
            IF NEW.is_active <> OLD.is_active
                AND (OLD.is_protected = 1 OR EXISTS (SELECT 1 FROM journal_lines WHERE financial_account_id = OLD.id))
                AND (OLD.type NOT IN ('cash', 'bank', 'wallet')
                OR OLD.branch_id IS NULL OR OLD.is_posting <> 1
                OR EXISTS (SELECT 1 FROM financial_accounts WHERE parent_id = OLD.id)) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'System and aggregate account status is protected';
            END IF;
        END");
        $this->installGuard('chart_journal_posting_guard_v2', 'journal_lines', 'INSERT', "BEGIN
            IF NOT EXISTS (SELECT 1 FROM financial_accounts WHERE id = NEW.financial_account_id AND is_posting = 1 AND is_active = 1) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posting requires an active posting account';
            END IF;
        END");

        DB::unprepared('DROP TRIGGER IF EXISTS chart_account_history_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS chart_journal_posting_guard');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Accounting safeguards require a separately reviewed rollback; no automatic removal.');
    }

    private function installGuard(string $name, string $table, string $event, string $body): void
    {
        $existing = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TRIGGER_NAME', $name)->first();
        if ($existing) {
            if (preg_replace('/\s+/', ' ', trim($existing->ACTION_STATEMENT)) !== preg_replace('/\s+/', ' ', trim($body))
                || $existing->EVENT_OBJECT_TABLE !== $table || $existing->EVENT_MANIPULATION !== $event || $existing->ACTION_TIMING !== 'BEFORE') {
                throw new RuntimeException('An unexpected existing accounting guard requires review.');
            }

            return;
        }

        DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW {$body}");
    }
};
