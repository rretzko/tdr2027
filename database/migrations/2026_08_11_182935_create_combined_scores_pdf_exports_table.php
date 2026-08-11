<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('combined_scores_pdf_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->boolean('confidential');
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('report_generation')->nullable();
            $table->string('s3_key')->nullable();
            $table->string('status');
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['version_id', 'confidential']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('combined_scores_pdf_exports');
    }
};
