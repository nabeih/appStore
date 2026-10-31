<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->string('thumbnail_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->dropColumn('thumbnail_path');
        });
    }
};
