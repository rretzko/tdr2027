<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ensemble_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ensemble_id')->constrained('ensembles')->cascadeOnDelete();
            $table->unsignedSmallInteger('grade');
            $table->timestamps();

            $table->unique(['ensemble_id', 'grade']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ensemble_grades');
    }
};
