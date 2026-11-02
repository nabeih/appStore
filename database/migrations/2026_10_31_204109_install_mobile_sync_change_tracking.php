<?php

use App\Support\Mobile\MobileChangeTracking;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(MobileChangeTracking::class)->install();
    }

    public function down(): void
    {
        throw new RuntimeException('Do not remove sync tracking without stopping mobile clients and approving a cursor reset/recovery plan.');
    }
};
