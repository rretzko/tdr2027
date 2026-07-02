<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table) {
            // Custom PK: version_id concatenated with unique 4-digit suffix (1000–9999).
            // Set by CandidateObserver on creating; not auto-incremented.
            $table->unsignedBigInteger('id')->primary();
            $table->string('ref')->unique();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('teacher_id')->constrained('teachers');
            $table->foreignId('voice_part_id')->constrained('voice_parts');
            $table->string('status')->default('eligible');
            $table->string('program_name');
            $table->foreignId('emergency_contact_id')->nullable()->constrained('emergency_contacts')->nullOnDelete();
            $table->timestamps();

            $table->unique(['version_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
