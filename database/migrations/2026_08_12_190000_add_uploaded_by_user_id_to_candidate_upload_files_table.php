<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Needed so an approved upload can be synced into `recordings`
     * (uploaded_by there is NOT NULL) — previously only who *decided* on an
     * upload was tracked, not who submitted it.
     */
    public function up(): void
    {
        Schema::table('candidate_upload_files', function (Blueprint $table) {
            $table->foreignId('uploaded_by_user_id')->nullable()->after('candidate_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('candidate_upload_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('uploaded_by_user_id');
        });
    }
};
