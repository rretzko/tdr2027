<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status changes aren't always made by a signed-in user: the Event Manager's
 * signed approve link (VersionInvitationRequestController) is deliberately
 * unauthenticated, yet approving triggers auto-enrollment, whose Candidates
 * each get an initial history row. With user_id NOT NULL that insert threw
 * after the first Candidate, silently skipping the rest of the teacher's
 * students. A NULL user_id now means "system-initiated".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_status_history', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('candidate_status_history', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
