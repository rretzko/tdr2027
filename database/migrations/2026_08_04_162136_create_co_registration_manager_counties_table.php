<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('co_registration_manager_counties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('county_id')->constrained('counties')->cascadeOnDelete();
            $table->timestamps();

            // A county can only be a single Co-Registration Manager's responsibility
            // per Version — enforces mutual exclusivity at the data layer.
            $table->unique(['version_id', 'county_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('co_registration_manager_counties');
    }
};
