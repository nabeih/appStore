<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->string('recognition_method', 20)->default('immediate');
        });
        Schema::create('expense_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_id')->constrained()->restrictOnDelete();
            $table->date('month');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date')->index();
            $table->unsignedBigInteger('amount_minor');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['expense_id', 'month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Financial schedules must not be removed by automatic rollback. Use an approved recovery procedure.');
    }
};
