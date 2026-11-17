<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adjudication_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->foreignId('room_judge_id')->constrained('room_judges')->cascadeOnDelete();

            // candidates.id is a custom unsignedBigInteger PK — same explicit
            // foreign() pattern as scores.
            $table->unsignedBigInteger('candidate_id');
            $table->foreign('candidate_id')->references('id')->on('candidates')->cascadeOnDelete();

            $table->foreignId('recording_id')->nullable()->constrained('recordings')->nullOnDelete();
            $table->string('event');
            $table->unsignedInteger('position_seconds')->nullable();
            $table->unsignedSmallInteger('roster_position')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['version_id', 'room_judge_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjudication_events');
    }
};
