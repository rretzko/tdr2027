<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TDR's own platform-usage invoice (MFR Holdings, LLC → the Event's
        // organization), scheduled by Close Audition and sent 24h later by
        // invoices:send-due. One row per Version: the unique version_id IS
        // the "send once" guarantee. See docs/plans/close-audition-invoice.md.
        Schema::create('version_platform_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->unique()->constrained('versions')->cascadeOnDelete();
            $table->string('number')->nullable()->unique();
            // App\Enums\PlatformInvoiceStatus: pending | held | sent | paid | void
            $table->string('status');
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('send_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->unsignedInteger('registrant_count')->nullable();
            $table->unsignedInteger('rate_cents')->nullable();
            $table->unsignedInteger('amount_cents')->nullable();
            $table->json('recipients')->nullable();
            $table->boolean('needs_attention')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_note')->nullable();
            $table->timestamp('last_resent_at')->nullable();
            $table->unsignedSmallInteger('resend_count')->default(0);
            $table->string('voided_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'send_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_platform_invoices');
    }
};
