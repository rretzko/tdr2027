<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('version_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->unique()->constrained('versions')->cascadeOnDelete();
            $table->longText('student_endorsement_body');
            $table->longText('parent_endorsement_body');
            $table->longText('teacher_principal_endorsement_body')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_applications');
    }
};
