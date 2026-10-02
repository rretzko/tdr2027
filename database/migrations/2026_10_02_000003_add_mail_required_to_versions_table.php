<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether teachers must mail physical materials (signed applications,
 * checks, membership cards, forms) to complete registration — decides
 * whether the readiness checklist asks for a postmark deadline and a
 * mail-to address (docs/plans/version-readiness-setup-questions.md §2a).
 * Separate from application_type: online-application events can still
 * collect checks by mail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->boolean('mail_required')->default(false)->after('share_results');
        });

        // Backfill so every existing Version's checklist is unchanged: these
        // are exactly the conditions that switched the two items on before.
        DB::table('versions')
            ->where('application_type', 'pdf')
            ->orWhereIn('id', DB::table('version_membership_requirements')
                ->where('membership_card', true)
                ->select('version_id'))
            ->update(['mail_required' => true]);
    }

    public function down(): void
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->dropColumn('mail_required');
        });
    }
};
