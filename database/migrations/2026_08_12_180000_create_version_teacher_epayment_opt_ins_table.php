<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('version_teacher_epayment_opt_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->boolean('opted_in')->default(false);
            $table->timestamps();

            $table->unique(['version_id', 'teacher_id'], 'version_teacher_epayment_opt_ins_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_teacher_epayment_opt_ins');
    }
};
