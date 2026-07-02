<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('version_membership_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->boolean('membership_card')->default(false);
            $table->date('valid_thru')->nullable();
            $table->timestamps();

            $table->unique('version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_membership_requirements');
    }
};
