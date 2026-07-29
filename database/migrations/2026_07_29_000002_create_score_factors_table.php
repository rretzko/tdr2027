<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('score_factors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('version_id')->nullable()->constrained('versions')->cascadeOnDelete();
            $table->foreignId('score_category_id')->constrained('score_categories')->cascadeOnDelete();
            $table->string('description');
            $table->string('abbreviation');
            $table->unsignedTinyInteger('best');
            $table->unsignedTinyInteger('worst');
            $table->unsignedTinyInteger('interval_by')->default(1);
            $table->unsignedTinyInteger('multiplier')->default(1);
            $table->unsignedTinyInteger('tolerance')->nullable();
            $table->unsignedTinyInteger('order_by')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_factors');
    }
};
