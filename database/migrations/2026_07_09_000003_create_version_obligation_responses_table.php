<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('version_obligation_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_invitation_id')->constrained('version_invitations')->cascadeOnDelete();
            $table->foreignId('version_obligation_id')->constrained('version_obligations')->cascadeOnDelete();
            $table->string('decision');
            $table->timestamp('decided_at');
            $table->longText('obligation_snapshot');
            $table->timestamps();

            $table->unique('version_invitation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_obligation_responses');
    }
};
