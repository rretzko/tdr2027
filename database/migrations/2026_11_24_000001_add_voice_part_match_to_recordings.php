<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties each recording to the voice part it was uploaded for. NULL means
 * unknown (uploaded before this existed) — deliberately not backfilled from
 * the candidate's current part, which would hide past mismatches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_upload_files', function (Blueprint $table): void {
            $table->foreignId('voice_part_id')->nullable()->constrained('voice_parts')->nullOnDelete();
        });

        Schema::table('recordings', function (Blueprint $table): void {
            $table->foreignId('voice_part_id')->nullable()->constrained('voice_parts')->nullOnDelete();
        });

        Schema::table('candidates', function (Blueprint $table): void {
            $table->timestamp('voice_part_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table): void {
            $table->dropColumn('voice_part_confirmed_at');
        });

        Schema::table('recordings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('voice_part_id');
        });

        Schema::table('candidate_upload_files', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('voice_part_id');
        });
    }
};
