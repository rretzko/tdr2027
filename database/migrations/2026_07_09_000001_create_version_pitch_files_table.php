<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('version_pitch_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->foreignId('voice_part_id')->constrained('voice_parts')->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('url');
            $table->unsignedSmallInteger('order_by')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_pitch_files');
    }
};
