<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_score_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('version_rooms')->cascadeOnDelete();
            $table->foreignId('score_category_id')->constrained('score_categories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['room_id', 'score_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_score_categories');
    }
};
