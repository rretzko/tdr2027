<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Founder-only per-Event override of the platform invoice's
        // per-registrant rate; null falls back to
        // config('billing.platform_fee_per_registrant_cents').
        Schema::table('events', function (Blueprint $table) {
            $table->unsignedInteger('platform_fee_per_registrant_cents')->nullable()->after('ensemble_count');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('platform_fee_per_registrant_cents');
        });
    }
};
