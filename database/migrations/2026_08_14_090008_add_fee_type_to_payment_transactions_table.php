<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            // FeeType (App\Enums\FeeType): registration | participation — which
            // of the two, never-combined checkout amounts this row covers.
            // Null for source=manual, same as payment_type's own nullability.
            $table->string('fee_type')->nullable()->after('payment_type');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn('fee_type');
        });
    }
};
