<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Event-scoped, not Version-scoped, and not a rename of epayment_credentials
 * — corrected 2026-08-13, mid-implementation of epayment-integration.md §4
 * step 4, after a real screenshot showed two Events reporting to the same
 * Organization (CJMEA, NJMEA) are actually separate Square businesses. A
 * vendor credential belongs to the business, which is realistically tied to
 * the Event, not to each individual Version of it — see §1.2/§5 item 8.
 * `version_epayment_configs` (a separate migration/table) keeps the
 * per-Version epayment_student/epayment_teacher accept-flags, which
 * genuinely are a per-Version Event Manager decision (§0).
 *
 * epayment_credentials itself is untouched here for the same reason as
 * before: it's still read live by CandidateDetail via
 * Version::epaymentCredential() until §4 steps 5/9 cut that code over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_epayment_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();

            // Vendor: square | paypal, nullable. Null = e-payment not
            // accepted at all for this Event — one vendor per Event, not
            // both simultaneously (epayment-integration.md §1.2/§5 item 6).
            $table->string('vendor')->nullable();

            // The Event's merchant/account/location id at that vendor (was
            // epayment_id on the old table; Square calls this a location_id).
            $table->string('vendor_account_id')->nullable();

            // The vendor API access token/secret for this specific business
            // — genuinely required per-business, not app-wide (confirmed
            // 2026-08-13: SquarePaymentGateway was first built reading a
            // single global env token, which cannot work now that multiple
            // separate Square businesses are confirmed to exist).
            $table->text('secret')->nullable();

            // The webhook signature-verification key for this business's own
            // webhook subscription — still open whether this is genuinely
            // needed per-business or can stay app-wide (§5 item 8); stored
            // here regardless so the per-business webhook route (once built)
            // has somewhere to read it from.
            $table->text('webhook_signature_key')->nullable();

            $table->timestamps();

            $table->unique('event_id');
        });

        // Best-effort backfill from epayment_credentials, which was
        // Version-scoped. That table was always schema-only with no admin UI
        // (a row had to be created directly, e.g. via tinker) — so this is a
        // one-time collapse of test/placeholder data, not real production
        // credentials: for each Event with any epayment_credentials row
        // across its Versions, take the most recently updated one.
        DB::table('epayment_credentials as ec')
            ->join('versions as v', 'v.id', '=', 'ec.version_id')
            ->select('ec.*', 'v.event_id')
            ->orderBy('ec.updated_at')
            ->get()
            ->groupBy('event_id')
            ->each(function ($rows, $eventId): void {
                $latest = $rows->last();

                DB::table('event_epayment_configs')->insert([
                    'event_id' => $eventId,
                    'vendor' => null,
                    'vendor_account_id' => $latest->epayment_id,
                    'secret' => $latest->secret,
                    'webhook_signature_key' => null,
                    'created_at' => $latest->created_at,
                    'updated_at' => $latest->updated_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_epayment_configs');
    }
};
