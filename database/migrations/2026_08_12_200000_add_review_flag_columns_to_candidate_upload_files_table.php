<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backs the rudimentary mis-filed-recording assist (RecordingReviewService):
     * a filename/slot-name mismatch check and a per-slot duration outlier
     * check, run at upload time and surfaced as a non-blocking flag for the
     * teacher's own review — never an automatic reject.
     */
    public function up(): void
    {
        Schema::table('candidate_upload_files', function (Blueprint $table) {
            $table->string('original_filename')->nullable()->after('url');
            $table->unsignedInteger('duration_seconds')->nullable()->after('original_filename');
            $table->timestamp('flagged_at')->nullable()->after('duration_seconds');
            $table->text('flag_reason')->nullable()->after('flagged_at');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_upload_files', function (Blueprint $table) {
            $table->dropColumn(['original_filename', 'duration_seconds', 'flagged_at', 'flag_reason']);
        });
    }
};
