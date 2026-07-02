<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('version_ensemble_order', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->foreignId('ensemble_id')->constrained('ensembles')->cascadeOnDelete();
            $table->unsignedTinyInteger('order_by')->default(1);
            $table->timestamps();

            $table->unique(['version_id', 'ensemble_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_ensemble_order');
    }
};
