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
        Schema::create('home_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained('students');
            $table->string('address1');
            $table->string('address2')->nullable();
            $table->string('city');
            $table->string('geo_state', 2);
            $table->string('zip_code', 10);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('home_addresses');
    }
};
