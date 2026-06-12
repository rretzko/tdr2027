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
        Schema::create('geostates', function (Blueprint $table) {
            $table->id();
            $table->string('country_abbr');
            $table->string('name');
            $table->string('abbr');
            $table->timestamps();

            $table->unique(['country_abbr', 'abbr']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('geostates');
    }
};
