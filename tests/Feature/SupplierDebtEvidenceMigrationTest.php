<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupplierDebtEvidenceMigrationTest extends TestCase
{
    private ?string $originalConnection = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('MIGRATION_REVIEW_MYSQL') !== '1') {
            $this->markTestSkipped('Opt-in isolated MySQL migration test; keeps generated databases.');
        }
        $source = config('database.connections.mysql');
        if (! app()->environment('testing') || app()->configurationIsCached() || config('database.default') !== 'mysql'
            || $source['database'] !== 'appstore_account_status_20261118_tests' || $source['host'] !== '127.0.0.1'
            || (string) $source['port'] !== '3306' || ! empty($source['url']) || isset($source['read']) || isset($source['write'])) {
            throw new LogicException('Refusing any source except the isolated local MySQL sandbox.');
        }
        $this->originalConnection = DB::getDefaultConnection();
        $database = 'appstore_debt_evidence_review_'.bin2hex(random_bytes(8));
        DB::statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        config(['database.connections.debt_evidence_review' => array_replace($source, ['database' => $database, 'name' => 'debt_evidence_review'])]);
        DB::setDefaultConnection('debt_evidence_review');
        $this->assertSame($database, DB::selectOne('SELECT DATABASE() AS db')->db);
        foreach (['users', 'suppliers', 'branches', 'purchase_invoices'] as $table) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
            });
            DB::table($table)->insert(['id' => 1]);
        }
        Schema::create('supplier_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
        });
        DB::table('supplier_payments')->insert(['id' => 1, 'purchase_invoice_id' => 1, 'amount_minor' => 150000]);
    }

    protected function tearDown(): void
    {
        if ($this->originalConnection !== null) {
            DB::purge('debt_evidence_review');
            DB::setDefaultConnection($this->originalConnection);
        }
        parent::tearDown();
    }

    private function row(string $status = 'draft'): array
    {
        return ['client_uuid' => (string) Str::uuid(), 'request_hash' => hash('sha256', Str::random()), 'origin_fingerprint' => hash('sha256', Str::random()),
            'approval_scope' => $status === 'approved' ? hash('sha256', 'supplier-1-branch-1') : null,
            'supplier_id' => 1, 'branch_id' => 1, 'created_by' => 1, 'approved_by' => $status === 'draft' ? null : 1,
            'status' => $status, 'original_date' => '2020-01-01', 'tracking_started_on' => '2025-01-01', 'posting_date' => '2026-01-01',
            'amount_minor' => 500000, 'paid_minor' => 0, 'reference' => 'LEGACY-REF', 'notes' => 'Synthetic supplier debt retained during schema upgrade',
            'evidence_path' => 'synthetic-history/statement.pdf', 'evidence_name' => 'statement.pdf', 'evidence_sha256' => hash('sha256', 'synthetic'),
            'financial_posted_at' => $status === 'draft' ? null : '2026-01-01 10:00:00'];
    }

    public static function installationStates(): array
    {
        return ['new installation' => [false], 'already migrated with history' => [true]];
    }

    #[DataProvider('installationStates')]
    public function test_forward_migration_supports_new_and_existing_installations_without_changing_history(bool $withHistory): void
    {
        $base = database_path('migrations/2026_11_21_164018_create_supplier_debt_settlements.php');
        $baseHash = hash_file('sha256', $base);
        (require $base)->up();
        if ($withHistory) {
            foreach (['draft', 'approved', 'reversed'] as $status) {
                DB::table('supplier_debt_settlements')->insert($this->row($status));
            }
        }
        $before = DB::table('supplier_debt_settlements')->orderBy('id')->get()->toArray();
        $triggers = DB::select('SHOW TRIGGERS');
        $migration = require database_path('migrations/2026_11_22_080914_make_supplier_debt_evidence_optional.php');
        $migration->up();
        $this->assertSame($baseHash, hash_file('sha256', $base));
        $this->assertEquals($before, DB::table('supplier_debt_settlements')->orderBy('id')->get()->toArray());
        $this->assertEquals($triggers, DB::select('SHOW TRIGGERS'));
        $this->assertSame(150000, (int) DB::table('supplier_payments')->value('amount_minor'));
        $this->assertNull(DB::table('supplier_payments')->value('supplier_debt_settlement_id'));
        $nullable = collect(Schema::getColumns('supplier_debt_settlements'))->whereIn('name', ['evidence_path', 'evidence_name', 'evidence_sha256']);
        $this->assertCount(3, $nullable);
        $this->assertTrue($nullable->every(fn ($column): bool => $column['nullable']));
        $row = array_replace($this->row(), ['evidence_path' => null, 'evidence_name' => null, 'evidence_sha256' => null]);
        DB::table('supplier_debt_settlements')->insert($row);
        $this->assertSame(1, DB::table('supplier_debt_settlements')->whereNull('evidence_path')->count());
        try {
            DB::table('supplier_debt_settlements')->insert(array_replace($this->row(), ['evidence_name' => null]));
            $this->fail('Partial evidence metadata must be rejected.');
        } catch (QueryException $exception) {
            $this->assertSame(3819, $exception->errorInfo[1]);
        }
        if ($withHistory) {
            try {
                DB::table('supplier_debt_settlements')->where('status', 'approved')->update(['evidence_path' => null, 'evidence_name' => null, 'evidence_sha256' => null]);
                $this->fail('Approved evidence history must remain immutable.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('immutable', $exception->getMessage());
            }
        }
        $schema = DB::selectOne('SHOW CREATE TABLE supplier_debt_settlements')->{'Create Table'};
        try {
            $migration->down();
            $this->fail('Automatic rollback would lose optional evidence support.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('must be retained', $exception->getMessage());
        }
        $this->assertSame($schema, DB::selectOne('SHOW CREATE TABLE supplier_debt_settlements')->{'Create Table'});
    }
}
