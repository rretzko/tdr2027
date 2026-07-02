<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epayment_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->string('epayment_id');
            $table->text('secret')->nullable();
            $table->timestamps();

            $table->unique('version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epayment_credentials');
    }
};
