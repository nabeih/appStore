<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_color_swatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('color_id')->constrained()->restrictOnDelete();
            $table->string('image_path', 255);
            $table->timestamps();
            $table->unique(['product_id', 'color_id']);
            $table->index('image_path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_color_swatches');
    }
};
