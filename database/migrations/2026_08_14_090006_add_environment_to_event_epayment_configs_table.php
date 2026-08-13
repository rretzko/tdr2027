<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An Event can now hold both a sandbox AND a production credential
 * simultaneously (one row per environment) instead of overwriting one with
 * the other — raised 2026-08-13 by the account owner while setting up a
 * real PayPal sandbox credential, once it became clear switching a business
 * from testing to live shouldn't destroy the sandbox row it was verified
 * against. Which row is "active" app-wide is still a single deployment-wide
 * toggle (services.payments.environment, not per-Event) — see
 * epayment-integration.md §1.2/§5 for the fuller writeup.
 *
 * default('sandbox') on the new column, rather than requiring a backfill
 * step, because the one real row in this table as of this migration (a
 * PayPal sandbox credential entered directly by the account owner) is in
 * fact a sandbox credential — the default happens to already be correct for
 * every row that exists today, not just a technical convenience.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_epayment_configs', function (Blueprint $table) {
            $table->string('environment')->default('sandbox')->after('event_id');
        });

        // The new composite unique index must be added BEFORE dropping the
        // old single-column one — MySQL/InnoDB won't drop event_id's unique
        // index while it's still the only index backing the event_id
        // foreign key. Adding the composite index first (its leftmost
        // column is event_id) gives the FK something else to use.
        Schema::table('event_epayment_configs', function (Blueprint $table) {
            $table->unique(['event_id', 'environment']);
        });

        Schema::table('event_epayment_configs', function (Blueprint $table) {
            $table->dropUnique(['event_id']);
        });
    }

    public function down(): void
    {
        Schema::table('event_epayment_configs', function (Blueprint $table) {
            $table->unique(['event_id']);
        });

        Schema::table('event_epayment_configs', function (Blueprint $table) {
            $table->dropUnique(['event_id', 'environment']);
        });

        Schema::table('event_epayment_configs', function (Blueprint $table) {
            $table->dropColumn('environment');
        });
    }
};
