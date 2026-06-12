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
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->unsignedTinyInteger('height')->nullable();
            $table->date('birthday')->nullable();
            $table->string('shirt_size')->default('med');
            $table->foreignId('instrument_id')->nullable()->constrained('instruments');
            $table->foreignId('voice_part_id')->nullable()->constrained('voice_parts');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
