<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('version_teacher_packets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();

            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Set when the Participating Schools "Send Confirmations" batch
            // action includes this row. Unchecking packet-received clears
            // received_at/received_by_user_id but never these two columns —
            // a confirmation already sent stays sent (see §5.10).
            $table->timestamp('confirmation_sent_at')->nullable();
            $table->foreignId('confirmation_sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['version_id', 'school_id', 'teacher_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_teacher_packets');
    }
};
