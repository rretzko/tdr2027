<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('version_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->string('status')->default('invited');
            $table->timestamp('invited_at');
            $table->foreignId('invited_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['version_id', 'teacher_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_invitations');
    }
};
