<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->unsignedInteger('senior_class_of')->default(2022);
            $table->string('status')->default('sandbox');
            $table->string('application_type')->default('pdf');
            $table->unsignedTinyInteger('audition_timeslot')->nullable()->default(20);
            $table->string('audition_type')->default('remote');
            $table->boolean('birthday')->default(false);
            $table->boolean('emergency_contact_name')->default(true);
            $table->boolean('emergency_contact_cell')->default(true);
            $table->boolean('emergency_contact_email')->default(false);
            $table->boolean('height')->default(false);
            $table->boolean('home_address')->default(false);
            $table->unsignedTinyInteger('judge_count')->default(1);
            $table->unsignedInteger('max_registrants')->nullable();
            $table->unsignedInteger('max_upper_voice_registrants')->nullable();
            $table->string('pitch_file_visibility')->default('both');
            $table->boolean('release_confidential_results')->default(false);
            $table->string('score_order')->default('asc');
            $table->boolean('shirt_size')->default(false);
            $table->boolean('teacher_cell')->default(true);
            $table->string('upload_type')->default('none');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versions');
    }
};
