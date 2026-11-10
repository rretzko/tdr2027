<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A teacher's "Not my student" on the Students page list of self-registered
     * students who share their school's email domain but never joined a school
     * (App\Services\UnjoinedSignupFinder). A district domain covers every school
     * in the district, so without this a teacher would see other schools'
     * sign-ups indefinitely.
     */
    public function up(): void
    {
        Schema::create('student_signup_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['teacher_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_signup_dismissals');
    }
};
