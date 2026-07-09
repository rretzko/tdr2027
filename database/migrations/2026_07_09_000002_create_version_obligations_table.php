<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('version_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->longText('body');
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_obligations');
    }
};
