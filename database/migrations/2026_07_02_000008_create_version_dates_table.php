<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('version_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->string('date_type');
            $table->timestamp('start_at');
            $table->timestamp('end_at')->nullable();
            $table->timestamps();

            $table->unique(['version_id', 'date_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_dates');
    }
};
