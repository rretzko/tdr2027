<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The current Ensemble Participants CSV for each Version × Ensemble: the
 * accepted candidates with contact details, built at Close Audition and on
 * demand (EnsembleParticipantsCsvService). The file itself lives on S3; a
 * regeneration replaces the row and deletes the old file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('version_ensemble_participant_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions', indexName: 'vep_exports_version_id_foreign')->cascadeOnDelete();
            $table->foreignId('ensemble_id')->constrained('ensembles', indexName: 'vep_exports_ensemble_id_foreign')->cascadeOnDelete();
            $table->string('s3_key');
            $table->string('filename');
            $table->unsignedInteger('row_count');
            $table->timestamp('generated_at');
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users', indexName: 'vep_exports_generated_by_foreign')->nullOnDelete();
            $table->timestamps();

            $table->unique(['version_id', 'ensemble_id'], 'version_ensemble_participant_exports_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_ensemble_participant_exports');
    }
};
