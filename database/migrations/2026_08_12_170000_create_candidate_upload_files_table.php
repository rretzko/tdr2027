<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_upload_files', function (Blueprint $table) {
            $table->id();

            // candidates.id is a custom unsignedBigInteger PK (not
            // bigIncrements) — same explicit foreign() pattern as
            // candidate_status_history/scores/candidate_payments.
            $table->unsignedBigInteger('candidate_id');
            $table->foreign('candidate_id')->references('id')->on('candidates')->cascadeOnDelete();

            $table->foreignId('version_upload_file_id')->constrained('version_upload_files')->cascadeOnDelete();

            $table->string('url');
            $table->string('status')->default('pending');
            $table->timestamp('uploaded_at');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['candidate_id', 'version_upload_file_id'], 'candidate_upload_files_candidate_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_upload_files');
    }
};
