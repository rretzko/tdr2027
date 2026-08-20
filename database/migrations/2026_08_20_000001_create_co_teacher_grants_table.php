<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('co_teacher_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('granting_teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->foreignId('co_teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->foreignId('granted_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // One grant per (school, granter, recipient) — re-granting an
            // already-granted pair is a no-op, not a duplicate row. Named
            // explicitly — the auto-generated name exceeds MySQL's 64-char
            // identifier limit.
            $table->unique(['school_id', 'granting_teacher_id', 'co_teacher_id'], 'co_teacher_grants_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('co_teacher_grants');
    }
};
