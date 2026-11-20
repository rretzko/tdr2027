<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->boolean('judge_survey')->default(true)->after('share_results');
        });

        // One row per judge per checkpoint, written when the popup is shown,
        // so a dismissed popup still counts towards the response rate and is
        // never shown again. room_judge_id is kept only to enforce that;
        // the Scorecard reports these rows in aggregate, never by judge.
        Schema::create('judge_survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->foreignId('room_judge_id')->constrained('room_judges')->cascadeOnDelete();
            $table->string('checkpoint');
            $table->unsignedSmallInteger('completed_count');
            $table->unsignedSmallInteger('roster_count');

            // Detected by the browser, never asked.
            $table->string('device_type')->nullable();
            $table->unsignedSmallInteger('screen_width')->nullable();

            $table->string('focus')->nullable();
            $table->json('obstacles')->nullable();
            $table->string('listening_device')->nullable();
            $table->unsignedTinyInteger('overall_rating')->nullable();
            $table->string('judge_again')->nullable();
            $table->text('comment')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['room_judge_id', 'checkpoint']);
            $table->index(['version_id', 'checkpoint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('judge_survey_responses');

        Schema::table('versions', function (Blueprint $table) {
            $table->dropColumn('judge_survey');
        });
    }
};
