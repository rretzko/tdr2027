<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-Version accept-electronic-payment flags only — the vendor credential
 * itself lives on event_epayment_configs (see that migration's docblock for
 * why this split exists). epayment_student/epayment_teacher are genuinely a
 * per-Version Event Manager decision (epayment-integration.md §0), unlike
 * the vendor credential, which is tied to the Event's Square/PayPal business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('version_epayment_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();

            // Event Manager: students may pay electronically. No consumer
            // until StudentFolder.info exists.
            $table->boolean('epayment_student')->default(false);

            // Event Manager: teachers may pay electronically.
            $table->boolean('epayment_teacher')->default(false);

            $table->timestamps();

            $table->unique('version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_epayment_configs');
    }
};
