<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_order_payments', function (Blueprint $table): void {
            $table->string('method', 20)->nullable();
        });
    }

    public function down(): void
    {
        throw new LogicException('Historical payment methods must be preserved; use a reviewed recovery plan.');
    }
};
