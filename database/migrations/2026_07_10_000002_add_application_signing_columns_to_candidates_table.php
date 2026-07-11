<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->timestamp('application_certified_at')->nullable();
            $table->foreignId('application_certified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('application_candidate_signed_at')->nullable();
            $table->timestamp('application_parent_signed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('application_certified_by_user_id');
            $table->dropColumn(['application_certified_at', 'application_candidate_signed_at', 'application_parent_signed_at']);
        });
    }
};
