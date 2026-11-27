<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // docs/plans/storage-included-billing.md: an Event's per-registrant
        // rate can be marked all-in, so its virtual Versions' invoices carry
        // no file-storage line. The invoice snapshots the term at send time
        // so a resend prints the same wording after the Event changes.
        // Both default to false: every existing Event and invoice keeps its
        // behavior, no backfill.
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('platform_fee_includes_storage')->default(false)->after('platform_fee_per_registrant_cents');
        });

        Schema::table('version_platform_invoices', function (Blueprint $table) {
            $table->boolean('storage_included')->default(false)->after('storage_rate_cents');
        });
    }

    public function down(): void
    {
        Schema::table('version_platform_invoices', function (Blueprint $table) {
            $table->dropColumn('storage_included');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('platform_fee_includes_storage');
        });
    }
};
