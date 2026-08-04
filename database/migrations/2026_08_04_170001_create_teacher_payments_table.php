<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();

            // PaymentType: check | purchase_order | cash | other | electronic.
            // The Participating Schools payment modal only ever writes the first
            // four — electronic is reserved until a teacher e-payment gateway
            // integration exists (see event-version-orientation.md §5.10/§8.15).
            $table->string('payment_type');

            $table->unsignedInteger('amount');
            $table->string('reference_number')->nullable();
            $table->text('comments')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_payments');
    }
};
