<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreditUsageSourceMigrationTest extends TestCase
{
    private ?string $originalConnection = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('MIGRATION_REVIEW_MYSQL') !== '1') {
            $this->markTestSkipped('Opt-in isolated MySQL DDL review.');
        }
        $source = config('database.connections.mysql');
        if (! app()->environment('testing') || app()->configurationIsCached()
            || config('database.default') !== 'mysql'
            || $source['database'] !== 'appstore_account_status_20261118_tests'
            || ! in_array($source['host'], ['127.0.0.1', 'localhost'], true)
            || (string) $source['port'] !== '3306' || ! empty($source['url'])
            || isset($source['read']) || isset($source['write'])) {
            throw new LogicException('Only the explicitly isolated local testing connection is allowed.');
        }
        $this->originalConnection = config('database.default');
        $database = 'appstore_credit_source_review_'.bin2hex(random_bytes(8));
        DB::connection('mysql')->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        config(['database.connections.credit_source_review' => array_replace($source, ['database' => $database, 'name' => 'credit_source_review'])]);
        DB::setDefaultConnection('credit_source_review');
        $this->assertSame($database, DB::selectOne('SELECT DATABASE() AS db')->db);
        Schema::create('customer_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->unsignedBigInteger('amount_minor');
        });
        Schema::create('sale_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 20);
            $table->unsignedBigInteger('credit_minor');
        });
        Schema::create('credit_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_return_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();
            $table->unique(['customer_receipt_id', 'sale_return_id']);
        });
        DB::table('customer_receipts')->insert(['id' => 5, 'kind' => 'credit', 'status' => 'approved', 'amount_minor' => 2000]);
        DB::table('sale_returns')->insert(['id' => 7, 'status' => 'approved', 'credit_minor' => 3500]);
        DB::table('credit_usages')->insert([
            'id' => 11, 'customer_receipt_id' => 5, 'sale_return_id' => 7,
            'amount_minor' => 2000, 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->originalConnection !== null) {
            DB::purge('credit_source_review');
            DB::setDefaultConnection($this->originalConnection);
        }
        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_21_130404_add_advance_receipt_source_to_credit_usages.php');
    }

    public static function sources(): array
    {
        return [
            'return only' => [7, null, true],
            'advance only' => [null, 6, true],
            'both' => [7, 6, false],
            'neither' => [null, null, false],
        ];
    }

    #[DataProvider('sources')]
    public function test_exactly_one_source_is_enforced_on_insert_and_update(?int $returnId, ?int $advanceId, bool $valid): void
    {
        $this->migration()->up();
        DB::table('customer_receipts')->insert([
            ['id' => 6, 'kind' => 'advance', 'status' => 'approved', 'amount_minor' => 5000],
            ['id' => 8, 'kind' => 'credit', 'status' => 'approved', 'amount_minor' => 1000],
        ]);
        $attributes = ['sale_return_id' => $returnId, 'advance_receipt_id' => $advanceId];
        foreach (['insert', 'update'] as $operation) {
            try {
                if ($operation === 'insert') {
                    DB::table('credit_usages')->insert($attributes + ['customer_receipt_id' => 8, 'amount_minor' => 1000]);
                } else {
                    DB::table('credit_usages')->where('id', 11)->update($attributes);
                }
                $this->assertTrue($valid, 'Invalid source shape was accepted.');
            } catch (QueryException $exception) {
                $this->assertFalse($valid);
                $this->assertSame(3819, $exception->errorInfo[1]);
            }
        }
    }

    public function test_legacy_rows_survive_upgrade_and_safe_rollback(): void
    {
        $before = (array) DB::table('credit_usages')->first();
        $migration = $this->migration();
        $migration->up();
        $after = (array) DB::table('credit_usages')->first();
        $this->assertNull($after['advance_receipt_id']);
        unset($after['advance_receipt_id']);
        $this->assertSame($before, $after);
        $constraint = DB::selectOne("SELECT ENFORCED AS enforced FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_usages' AND CONSTRAINT_NAME = 'credit_usages_exactly_one_source_check'");
        $this->assertSame('YES', $constraint->enforced);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('credit_usages', 'advance_receipt_id'));
        $this->assertSame($before, (array) DB::table('credit_usages')->first());
        $column = DB::selectOne("SELECT IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_usages' AND COLUMN_NAME = 'sale_return_id'");
        $this->assertSame('NO', $column->nullable);
        $this->assertCount(2, DB::select("SELECT * FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_usages' AND DELETE_RULE = 'RESTRICT'"));
    }

    public static function unsafeHistory(): array
    {
        return [
            'unused approved advance' => ['approved', false],
            'unused reversed advance' => ['reversed', false],
            'used approved advance' => ['approved', true],
            'used reversed advance' => ['reversed', true],
        ];
    }

    #[DataProvider('unsafeHistory')]
    public function test_rollback_preserves_all_advance_history_before_any_ddl(string $status, bool $used): void
    {
        $this->migration()->up();
        DB::table('customer_receipts')->insert(['id' => 6, 'kind' => 'advance', 'status' => $status, 'amount_minor' => 5000]);
        if ($used) {
            DB::table('credit_usages')->insert(['customer_receipt_id' => 5, 'sale_return_id' => null, 'advance_receipt_id' => 6, 'amount_minor' => 1000]);
        }
        $this->assertRollbackBlockedWithoutDdl();
    }

    public function test_rollback_checks_usage_even_without_an_advance_receipt_kind(): void
    {
        $this->migration()->up();
        DB::table('customer_receipts')->insert(['id' => 6, 'kind' => 'refund', 'status' => 'reversed', 'amount_minor' => 5000]);
        DB::table('credit_usages')->insert(['customer_receipt_id' => 5, 'sale_return_id' => null, 'advance_receipt_id' => 6, 'amount_minor' => 1000]);
        $this->assertRollbackBlockedWithoutDdl();
    }

    public function test_rollback_rejects_a_missing_return_before_any_ddl(): void
    {
        $this->migration()->up();
        DB::statement('ALTER TABLE credit_usages DROP CHECK credit_usages_exactly_one_source_check');
        DB::table('credit_usages')->where('id', 11)->update(['sale_return_id' => null]);
        $this->assertRollbackBlockedWithoutDdl();
    }

    private function assertRollbackBlockedWithoutDdl(): void
    {
        $before = DB::selectOne('SHOW CREATE TABLE credit_usages')->{'Create Table'};
        $rows = DB::table('credit_usages')->get()->toArray();
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->migration()->down();
            $this->fail('Unsafe rollback was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Rollback blocked', $exception->getMessage());
        } finally {
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
        }
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(ALTER|DROP|CREATE)\s/i', $query['query']);
        }
        $this->assertSame($before, DB::selectOne('SHOW CREATE TABLE credit_usages')->{'Create Table'});
        $this->assertEquals($rows, DB::table('credit_usages')->get()->toArray());
    }

    public function test_failed_rollback_ddl_is_atomic(): void
    {
        $this->migration()->up();
        DB::statement('ALTER TABLE credit_usages DROP INDEX credit_usages_customer_receipt_id_advance_receipt_id_unique');
        $before = DB::selectOne('SHOW CREATE TABLE credit_usages')->{'Create Table'};
        try {
            $this->migration()->down();
            $this->fail('Expected missing index failure.');
        } catch (QueryException $exception) {
            $this->assertSame(1091, $exception->errorInfo[1]);
        }
        $this->assertSame($before, DB::selectOne('SHOW CREATE TABLE credit_usages')->{'Create Table'});
        $this->assertSame(1, DB::table('credit_usages')->count());
    }

    public function test_upgrade_rejects_invalid_legacy_data_atomically(): void
    {
        DB::statement('ALTER TABLE credit_usages MODIFY COLUMN sale_return_id BIGINT UNSIGNED NULL');
        DB::table('credit_usages')->where('id', 11)->update(['sale_return_id' => null]);
        $before = DB::selectOne('SHOW CREATE TABLE credit_usages')->{'Create Table'};
        try {
            $this->migration()->up();
            $this->fail('Invalid legacy data must block the upgrade.');
        } catch (QueryException $exception) {
            $this->assertSame(3819, $exception->errorInfo[1]);
        }
        $this->assertSame($before, DB::selectOne('SHOW CREATE TABLE credit_usages')->{'Create Table'});
        $this->assertFalse(Schema::hasColumn('credit_usages', 'advance_receipt_id'));
        $this->assertNull(DB::table('credit_usages')->value('sale_return_id'));
    }

    public static function unsupportedVersions(): array
    {
        return [
            'MySQL 5.7' => ['5.7.44'],
            'before enforcement' => ['8.0.15'],
            'MariaDB' => ['10.11.8-MariaDB'],
            'unknown version' => ['unverified'],
        ];
    }

    #[DataProvider('unsupportedVersions')]
    public function test_unsupported_server_is_rejected_before_ddl(string $version): void
    {
        $manager = DB::getFacadeRoot();
        DB::shouldReceive('getDriverName')->andReturn('mysql');
        DB::shouldReceive('selectOne')->once()->with('SELECT VERSION() AS version')->andReturn((object) ['version' => $version]);
        DB::shouldReceive('statement')->never();
        try {
            $this->migration()->up();
            $this->fail('Unsupported server must be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('MySQL 8.0.16+', $exception->getMessage());
            $this->assertStringContainsString($version, $exception->getMessage());
        } finally {
            DB::swap($manager);
        }
        $this->assertFalse(Schema::hasColumn('credit_usages', 'advance_receipt_id'));
    }
}
