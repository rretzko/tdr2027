<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ensemble_voice_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ensemble_id')->constrained('ensembles')->cascadeOnDelete();
            $table->foreignId('voice_part_id')->constrained('voice_parts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ensemble_id', 'voice_part_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ensemble_voice_parts');
    }
};
