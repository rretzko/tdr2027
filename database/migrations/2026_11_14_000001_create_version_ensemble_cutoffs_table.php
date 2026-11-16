<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cutoff score applied to each Ensemble within each Voice Part, for the
 * per-Ensemble cut-off strategies (SequentialEnsembleFill,
 * GradeSegmentedEnsembles). Ensemble Cut-offs recomputes a Voice Part's
 * assignments from these rows on every score click, so a later click can
 * tell which Ensembles already have a line and which comes next.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('version_ensemble_cutoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->foreignId('voice_part_id')->constrained('voice_parts')->cascadeOnDelete();
            $table->foreignId('ensemble_id')->constrained('ensembles')->cascadeOnDelete();
            $table->integer('score');
            $table->timestamps();

            $table->unique(['version_id', 'voice_part_id', 'ensemble_id'], 'version_ensemble_cutoffs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_ensemble_cutoffs');
    }
};
