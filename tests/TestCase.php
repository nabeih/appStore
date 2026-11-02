<?php

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    /** Test-only DDL fixture; deliberately does not invoke a migration or reset any table. */
    protected function prepareFirstRunSchema(): void
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        if (! app()->environment('testing') || $connection->getDriverName() !== 'mysql'
            || ($database !== 'appstore_golive_20261031_tests' && ! preg_match('/\Aappstore_first_run_race_[a-f0-9]{16}\z/', $database))) {
            throw new \LogicException('First-run fixtures are restricted to the explicitly isolated MySQL test databases.');
        }
        if ($connection->transactionLevel() !== 0) {
            throw new \LogicException('Create fixture schema before opening test transactions.');
        }
        if (! Schema::hasTable('application_installations')) {
            Schema::create('application_installations', function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
                $table->string('status', 20);
                $table->char('token_hash', 64)->nullable();
                $table->uuid('delivery_id')->nullable();
                $table->timestamp('token_expires_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
        $state = DB::table('application_installations')->where('id', 1)->first();
        if ($state && ($state->completed_at !== null || $state->token_hash !== null)) {
            throw new \LogicException('Refusing to overwrite a completed or armed fixture installation.');
        }
        DB::table('application_installations')->updateOrInsert(['id' => 1], ['status' => 'blocked']);
    }
}
