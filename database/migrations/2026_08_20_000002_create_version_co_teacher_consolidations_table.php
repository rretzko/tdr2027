<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Table/column names are long enough that several of Laravel's
        // auto-generated constraint names exceed MySQL's 64-char identifier
        // limit — every constraint below is named explicitly (vctc_* prefix)
        // rather than left to chance.
        Schema::create('version_co_teacher_consolidations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions', 'id', 'vctc_version_id_foreign')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('schools', 'id', 'vctc_school_id_foreign')->cascadeOnDelete();
            // Canonicalized at write time (min/max by id) so either co-teacher
            // can set this without needing to know who's "first" — see
            // VersionCoTeacherConsolidation::canonicalTeacherIds().
            $table->foreignId('first_teacher_id')->constrained('teachers', 'id', 'vctc_first_teacher_id_foreign')->cascadeOnDelete();
            $table->foreignId('second_teacher_id')->constrained('teachers', 'id', 'vctc_second_teacher_id_foreign')->cascadeOnDelete();
            $table->foreignId('consolidated_teacher_id')->constrained('teachers', 'id', 'vctc_consolidated_teacher_id_foreign')->cascadeOnDelete();
            $table->foreignId('set_by_user_id')->constrained('users', 'id', 'vctc_set_by_user_id_foreign')->cascadeOnDelete();
            $table->timestamp('set_at');
            $table->timestamps();

            $table->unique(['version_id', 'school_id', 'first_teacher_id', 'second_teacher_id'], 'vctc_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_co_teacher_consolidations');
    }
};
