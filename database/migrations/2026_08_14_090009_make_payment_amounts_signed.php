<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds (PaymentType::Refund) store a negative amount rather than a
 * separate refund table, so both amount columns must accept negative
 * values. ->change(), not raw SQL — Laravel 11+'s native MySQL/SQLite
 * grammars support column-type changes without doctrine/dbal, and staying
 * on the Blueprint DSL keeps this visible to Larastan's static migration
 * parsing (a raw DB::statement() ALTER is invisible to it, so it would keep
 * inferring the original unsignedInteger everywhere this column is read).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->integer('amount')->change();
        });

        Schema::table('payment_allocations', function (Blueprint $table): void {
            $table->integer('amount')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->unsignedInteger('amount')->change();
        });

        Schema::table('payment_allocations', function (Blueprint $table): void {
            $table->unsignedInteger('amount')->change();
        });
    }
};
