<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_image_embeddings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_image_id')->constrained()->cascadeOnDelete();
            $table->string('model_version', 120);
            $table->string('image_path', 255);
            $table->char('image_hash', 64);
            $table->char('generation', 26);
            $table->unsignedSmallInteger('dimensions')->nullable();
            $table->binary('embedding')->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('last_error', 180)->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();
            $table->unique(['product_image_id', 'model_version'], 'image_embedding_model_unique');
            $table->index(['model_version', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_image_embeddings');
    }
};
