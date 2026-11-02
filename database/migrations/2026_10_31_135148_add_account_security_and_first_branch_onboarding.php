<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('email_verified_at')->nullable();
        });
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Schema::table('application_installations', function (Blueprint $table): void {
            $table->boolean('first_branch_required')->default(false);
            $table->timestamp('first_branch_completed_at')->nullable();
            $table->foreignId('initial_branch_id')->nullable()->constrained('branches')->nullOnDelete();
        });
        if (! DB::table('branches')->exists()) {
            DB::table('application_installations')->where('id', 1)->where('status', 'completed')
                ->whereNotNull('completed_at')->whereNotNull('owner_id')->update(['first_branch_required' => true]);
        }
    }

    public function down(): void
    {
        throw new LogicException('Account security and onboarding history require a separately approved recovery plan.');
    }
};
