<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();

            // candidates.id is a custom unsignedBigInteger PK (not bigIncrements),
            // same explicit foreign() pattern as candidate_status_history.
            $table->unsignedBigInteger('candidate_id');
            $table->foreign('candidate_id')->references('id')->on('candidates')->cascadeOnDelete();

            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('score_category_id')->constrained('score_categories')->cascadeOnDelete();
            $table->unsignedTinyInteger('score_category_order_by');
            $table->foreignId('score_factor_id')->constrained('score_factors')->cascadeOnDelete();
            $table->unsignedTinyInteger('score_factor_order_by');
            $table->foreignId('judge_id')->constrained('room_judges')->cascadeOnDelete();
            $table->unsignedTinyInteger('judge_order_by');
            $table->foreignId('voice_part_id')->constrained('voice_parts')->cascadeOnDelete();
            $table->unsignedSmallInteger('voice_part_order_by');
            $table->unsignedTinyInteger('score');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scores');
    }
};
