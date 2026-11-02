<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->nullable()->unique()->constrained('personal_access_tokens')->nullOnDelete();
            $table->string('name', 80);
            $table->char('credential_hash', 64);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('mobile_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('device_id')->constrained('mobile_devices');
            $table->uuid('operation_uuid')->unique();
            $table->foreignId('branch_id')->constrained();
            $table->string('operation_type', 40);
            $table->char('request_hash', 64);
            $table->json('payload');
            $table->json('dependencies');
            $table->timestamp('client_created_at');
            $table->string('status', 40);
            $table->json('result')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'operation_uuid']);
        });
        Schema::create('mobile_entity_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('device_id')->constrained('mobile_devices');
            $table->string('entity', 24);
            $table->uuid('client_uuid');
            $table->unsignedBigInteger('server_id');
            $table->timestamps();
            $table->unique(['device_id', 'entity', 'client_uuid'], 'mobile_mapping_client');
            $table->unique(['device_id', 'entity', 'server_id'], 'mobile_mapping_server');
        });
        Schema::create('mobile_sync_clock', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('version')->default(0);
        });
        Schema::create('mobile_sync_changes', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('entity', 32);
            $table->unsignedBigInteger('record_id');
            $table->unsignedBigInteger('branch_id')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['branch_id', 'id']);
            $table->index(['entity', 'record_id', 'id']);
        });
        Schema::create('mobile_sync_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained('mobile_devices');
            $table->foreignId('branch_id')->constrained();
            $table->char('access_hash', 64);
            $table->unsignedBigInteger('bootstrap_watermark');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::create('mobile_sync_records', function (Blueprint $table): void {
            $table->foreignUuid('session_id')->constrained('mobile_sync_sessions')->cascadeOnDelete();
            $table->string('entity', 32);
            $table->unsignedBigInteger('record_id');
            $table->primary(['session_id', 'entity', 'record_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Mobile operations and sync history require a reviewed recovery plan; automatic destructive rollback is disabled.');
    }
};
