<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('school_teacher_subject', function (Blueprint $table) {
            $table->foreignId('school_teacher_id')->constrained('school_teacher');
            $table->string('subject');
            $table->timestamps();

            $table->primary(['school_teacher_id', 'subject']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('school_teacher_subject');
    }
};
