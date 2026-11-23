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
        if (! $this->usesMysqlCheck()) {
            Schema::table('credit_usages', function (Blueprint $table): void {
                $table->unsignedBigInteger('sale_return_id')->nullable()->change();
                $table->foreignId('advance_receipt_id')->nullable()->constrained('customer_receipts')->restrictOnDelete();
                $table->unique(['customer_receipt_id', 'advance_receipt_id']);
            });

            return;
        }

        $table = DB::connection()->getQueryGrammar()->wrapTable('credit_usages');
        $receipts = DB::connection()->getQueryGrammar()->wrapTable('customer_receipts');
        DB::statement("ALTER TABLE {$table}
            MODIFY COLUMN `sale_return_id` BIGINT UNSIGNED NULL,
            ADD COLUMN `advance_receipt_id` BIGINT UNSIGNED NULL,
            ADD CONSTRAINT `credit_usages_advance_receipt_id_foreign`
                FOREIGN KEY (`advance_receipt_id`) REFERENCES {$receipts} (`id`) ON DELETE RESTRICT,
            ADD UNIQUE KEY `credit_usages_customer_receipt_id_advance_receipt_id_unique`
                (`customer_receipt_id`, `advance_receipt_id`),
            ADD CONSTRAINT `credit_usages_exactly_one_source_check`
                CHECK ((`sale_return_id` IS NULL) <> (`advance_receipt_id` IS NULL)) ENFORCED");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! $this->usesMysqlCheck()) {
            $this->assertRollbackSafe();
            Schema::table('credit_usages', function (Blueprint $table): void {
                $table->dropForeign(['advance_receipt_id']);
                $table->dropUnique(['customer_receipt_id', 'advance_receipt_id']);
                $table->dropColumn('advance_receipt_id');
                $table->unsignedBigInteger('sale_return_id')->nullable(false)->change();
            });

            return;
        }

        $table = DB::connection()->getQueryGrammar()->wrapTable('credit_usages');
        $receipts = DB::connection()->getQueryGrammar()->wrapTable('customer_receipts');
        DB::statement("LOCK TABLES {$table} WRITE, {$receipts} READ");
        try {
            $this->assertRollbackSafe();
            DB::statement("ALTER TABLE {$table}
                DROP CHECK `credit_usages_exactly_one_source_check`,
                DROP FOREIGN KEY `credit_usages_advance_receipt_id_foreign`,
                DROP INDEX `credit_usages_customer_receipt_id_advance_receipt_id_unique`,
                DROP COLUMN `advance_receipt_id`,
                MODIFY COLUMN `sale_return_id` BIGINT UNSIGNED NOT NULL");
        } finally {
            DB::statement('UNLOCK TABLES');
        }
    }

    private function assertRollbackSafe(): void
    {
        if (DB::table('credit_usages')->whereNotNull('advance_receipt_id')->exists()
            || DB::table('customer_receipts')->where('kind', 'advance')->exists()
            || DB::table('credit_usages')->whereNull('sale_return_id')->exists()) {
            throw new LogicException('Rollback blocked: advance receipts/usages (including reversed history) or missing return sources must be retained. Reversal does not erase history.');
        }
    }

    /** SQLite retains the existing schema path for legacy tests; financial acceptance requires MySQL. */
    private function usesMysqlCheck(): bool
    {
        if (DB::getDriverName() === 'sqlite' && app()->environment('testing')) {
            return false;
        }
        if (DB::getDriverName() !== 'mysql') {
            throw new LogicException('Customer advance schema requires MySQL 8.0.16+ with enforced CHECK constraints.');
        }
        $version = (string) DB::selectOne('SELECT VERSION() AS version')->version;
        if (stripos($version, 'mariadb') !== false || ! preg_match('/\A(\d+\.\d+\.\d+)(?:[-+].*)?\z/', $version, $matches)
            || version_compare($matches[1], '8.0.16', '<')) {
            throw new LogicException('MySQL 8.0.16+ is required; refusing unsupported or unverified server version: '.$version);
        }
        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Run this MySQL schema migration outside a data transaction.');
        }
        $engine = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [DB::connection()->getDatabaseName(), DB::connection()->getTablePrefix().'credit_usages']);
        if (strtoupper((string) ($engine?->engine ?? '')) !== 'INNODB') {
            throw new LogicException('InnoDB is required for atomic customer advance schema changes.');
        }

        return true;
    }
};
