<?php

use App\Actions\Access\FirstRun\InstallationReadiness;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
        DB::table('application_installations')->insert([
            'id' => 1, 'status' => app(InstallationReadiness::class)->isPristine() ? 'unprepared' : 'blocked',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Installation state is permanent. Rollback requires a separately approved recovery plan.');
    }
};
