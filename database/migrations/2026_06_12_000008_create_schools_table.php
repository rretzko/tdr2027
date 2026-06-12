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
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('city');
            $table->string('zip_code', 5);
            $table->foreignId('geostate_id')->nullable()->constrained('geostates');
            $table->foreignId('county_id')->constrained('counties');
            $table->string('school_year')->default('US');
            $table->timestamps();

            $table->unique(['name', 'zip_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
