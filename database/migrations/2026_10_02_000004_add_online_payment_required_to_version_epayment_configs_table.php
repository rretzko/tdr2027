<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Online only": teachers must settle their balance online rather than by
 * check. Informational — shown to teachers wherever they see their balance;
 * managers can still record a check or purchase order as an exception.
 * Only meaningful when epayment_teacher is on; never applies to student
 * payments, which are always optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('version_epayment_configs', function (Blueprint $table) {
            $table->boolean('online_payment_required')->default(false)->after('epayment_teacher');
        });
    }

    public function down(): void
    {
        Schema::table('version_epayment_configs', function (Blueprint $table) {
            $table->dropColumn('online_payment_required');
        });
    }
};
