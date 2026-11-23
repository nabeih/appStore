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
        if (DB::getDriverName() === 'sqlite' && app()->environment('testing')) {
            Schema::table('supplier_debt_settlements', function (Blueprint $table): void {
                $table->string('evidence_path', 500)->nullable()->change();
                $table->string('evidence_name')->nullable()->change();
                $table->char('evidence_sha256', 64)->nullable()->change();
            });

            return;
        }
        if (DB::getDriverName() !== 'mysql') {
            throw new LogicException('Optional supplier evidence requires MySQL accounting safeguards.');
        }
        $version = DB::selectOne('SELECT VERSION() AS version')->version;
        if (stripos($version, 'mariadb') !== false || version_compare($version, '8.0.16', '<')) {
            throw new LogicException('MySQL 8.0.16+ is required for enforced evidence consistency.');
        }
        $table = DB::connection()->getQueryGrammar()->wrapTable('supplier_debt_settlements');
        DB::statement("ALTER TABLE {$table}
            MODIFY COLUMN evidence_path VARCHAR(500) NULL,
            MODIFY COLUMN evidence_name VARCHAR(255) NULL,
            MODIFY COLUMN evidence_sha256 CHAR(64) NULL,
            ADD CONSTRAINT supplier_debt_evidence_consistency CHECK (
                (evidence_path IS NULL AND evidence_name IS NULL AND evidence_sha256 IS NULL)
                OR (evidence_path IS NOT NULL AND evidence_name IS NOT NULL AND evidence_sha256 IS NOT NULL)
            ) ENFORCED");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Optional evidence history must be retained; reverting to mandatory files requires a separately reviewed recovery plan.');
    }
};
