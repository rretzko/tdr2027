<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Virtual (Remote + Audio/Video upload) Versions add a file-storage
        // line: one month before the Candidate date's start_at through one
        // month after results_released_at, partial months rounded up.
        // The two source dates are stored (not the shifted window) so the
        // invoice can print "Candidate Access Date (…) - Event Close Date (…)"
        // exactly; the ±1 month window is derived (StorageCharge).
        // Snapshotted at send time like the registrant line.
        // attention_reason says why a needs_attention invoice is stuck
        // (no Event Managers / no Candidate date).
        Schema::table('version_platform_invoices', function (Blueprint $table) {
            $table->timestamp('storage_access_at')->nullable()->after('amount_cents');
            $table->timestamp('storage_close_at')->nullable()->after('storage_access_at');
            $table->unsignedSmallInteger('storage_months')->nullable()->after('storage_close_at');
            $table->unsignedInteger('storage_rate_cents')->nullable()->after('storage_months');
            $table->string('attention_reason')->nullable()->after('needs_attention');
        });
    }

    public function down(): void
    {
        Schema::table('version_platform_invoices', function (Blueprint $table) {
            $table->dropColumn(['storage_access_at', 'storage_close_at', 'storage_months', 'storage_rate_cents', 'attention_reason']);
        });
    }
};
