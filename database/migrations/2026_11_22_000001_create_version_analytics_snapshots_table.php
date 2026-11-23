<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One frozen copy of a Version's end-of-cycle analytics, captured at Close
 * Audition (docs/plans/version-analytics-report.md § Prerequisites). The
 * report and any cross-Version comparison read these, not live data, so a
 * later score edit or deletion can't rewrite history. A re-Close after a
 * Reopen replaces the row: reopening is for corrections.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('version_analytics_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->unique()->constrained('versions')->cascadeOnDelete();
            // Bumped when a section's shape changes, so the report can tell
            // which shape an older snapshot was written in.
            $table->unsignedSmallInteger('schema_version');
            $table->timestamp('captured_at');
            $table->foreignId('captured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('registration');
            $table->json('adjudication');
            $table->json('selection');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_analytics_snapshots');
    }
};
