<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audition_results', function (Blueprint $table) {
            $table->id();

            // candidates.id is a custom unsignedBigInteger PK (not
            // bigIncrements), same explicit foreign() pattern as scores
            // and candidate_status_history. Unique — one frozen tally
            // row per candidate, written once at Version close.
            $table->unsignedBigInteger('candidate_id')->unique();
            $table->foreign('candidate_id')->references('id')->on('candidates')->cascadeOnDelete();

            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->foreignId('voice_part_id')->constrained('voice_parts')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->unsignedSmallInteger('voice_part_order_by');
            $table->unsignedSmallInteger('score_count');
            $table->unsignedMediumInteger('total');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audition_results');
    }
};
