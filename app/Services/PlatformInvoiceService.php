<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditionType;
use App\Enums\CandidateStatus;
use App\Enums\EventStatus;
use App\Enums\PlatformInvoiceSendOutcome;
use App\Enums\PlatformInvoiceStatus;
use App\Enums\UploadType;
use App\Enums\VersionDateType;
use App\Mail\PlatformInvoiceMail;
use App\Models\Candidate;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionPlatformInvoice;
use App\Support\Billing\PlatformInvoiceData;
use App\Support\Billing\StorageCharge;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * TDR's platform-usage invoice (docs/plans/close-audition-invoice.md).
 *
 * Close Audition schedules a Pending row with send_at = +24h rather than
 * dispatching a delayed job: prod runs SQS on Vapor, which caps job delay at
 * 15 minutes. invoices:send-due (every 15 min) sends rows that are due.
 * Everything billable — registrant count, rate, recipients — is snapshotted
 * at send time, not at click time, so last-minute corrections made inside
 * the 24h window are reflected. A Version is invoiced at most once (unique
 * version_id); Resend re-mails the stored snapshot, never a recomputation.
 *
 * Virtual Versions (Remote + Audio/Video uploads) get a second line for file
 * storage — see storageCharge(). A virtual Version with no Candidate date is
 * held for the Founder's attention rather than sent without that line.
 */
final class PlatformInvoiceService
{
    public const ATTENTION_NO_RECIPIENTS = 'No Event Managers';

    public const ATTENTION_NO_CANDIDATE_DATE = 'No Candidate date (needed for storage)';

    public function __construct(private readonly VersionRoleAssignmentService $roles) {}

    public function scheduleOnClose(Version $version, ?User $closedBy): VersionPlatformInvoice
    {
        return DB::transaction(function () use ($version, $closedBy): VersionPlatformInvoice {
            $invoice = VersionPlatformInvoice::where('version_id', $version->id)->lockForUpdate()->first();

            if ($invoice === null) {
                return VersionPlatformInvoice::create([
                    'version_id' => $version->id,
                    'status' => PlatformInvoiceStatus::Pending->value,
                    'closed_by_user_id' => $closedBy?->id,
                    'send_at' => $this->sendAtFrom(Carbon::now()),
                ]);
            }

            // Re-close before the invoice went out restarts the 24h clock;
            // anything already sent/paid/void is left alone (send once).
            if (in_array($invoice->statusEnum(), [PlatformInvoiceStatus::Pending, PlatformInvoiceStatus::Held], true)) {
                $invoice->update([
                    'status' => PlatformInvoiceStatus::Pending->value,
                    'closed_by_user_id' => $closedBy?->id,
                    'send_at' => $this->sendAtFrom(Carbon::now()),
                    'needs_attention' => false,
                    'attention_reason' => null,
                ]);
            }

            return $invoice;
        });
    }

    public function holdOnReopen(Version $version): void
    {
        VersionPlatformInvoice::where('version_id', $version->id)
            ->where('status', PlatformInvoiceStatus::Pending->value)
            ->update([
                'status' => PlatformInvoiceStatus::Held->value,
                'send_at' => null,
                'needs_attention' => false,
                'attention_reason' => null,
            ]);
    }

    /**
     * Sends every Pending invoice whose send_at has passed.
     *
     * @return array<string, int> outcome value => count
     */
    public function sendDue(): array
    {
        $ids = VersionPlatformInvoice::query()
            ->where('status', PlatformInvoiceStatus::Pending->value)
            ->whereNotNull('send_at')
            ->where('send_at', '<=', Carbon::now())
            ->orderBy('send_at')
            ->pluck('id');

        $tally = [];

        foreach ($ids as $id) {
            $invoice = VersionPlatformInvoice::find($id);

            if ($invoice === null) {
                continue;
            }

            // One bad invoice (e.g. a mail transport error) must not block
            // the rest; its transaction rolled back, so it stays Pending and
            // the next run retries it.
            try {
                $outcome = $this->send($invoice)->value;
            } catch (Throwable $e) {
                report($e);
                $outcome = 'failed';
            }

            $tally[$outcome] = ($tally[$outcome] ?? 0) + 1;
        }

        return $tally;
    }

    /**
     * Snapshots, numbers and emails one Pending invoice. The mail is sent
     * inside the transaction so a mail failure rolls the row back to Pending
     * and the next run retries it.
     */
    public function send(VersionPlatformInvoice $invoice): PlatformInvoiceSendOutcome
    {
        $founderAlert = null;

        $outcome = DB::transaction(function () use ($invoice, &$founderAlert): PlatformInvoiceSendOutcome {
            $locked = VersionPlatformInvoice::whereKey($invoice->id)->lockForUpdate()->first();

            if ($locked === null || $locked->statusEnum() !== PlatformInvoiceStatus::Pending) {
                return PlatformInvoiceSendOutcome::Skipped;
            }

            $version = Version::with('event.organization')->find($locked->version_id);

            if ($version === null || $version->getRawOriginal('status') !== EventStatus::Closed->value) {
                $locked->update(['status' => PlatformInvoiceStatus::Held->value, 'send_at' => null]);

                return PlatformInvoiceSendOutcome::Held;
            }

            $registrantCount = $this->registrantCount($version);
            $rateCents = $this->rateCents($version);

            if ($registrantCount === 0) {
                $locked->update([
                    'status' => PlatformInvoiceStatus::Void->value,
                    'send_at' => null,
                    'registrant_count' => 0,
                    'rate_cents' => $rateCents,
                    'amount_cents' => 0,
                    'voided_reason' => 'Zero registrants',
                ]);

                return PlatformInvoiceSendOutcome::Voided;
            }

            $storage = null;

            if ($this->isVirtual($version)) {
                $storage = $this->storageCharge($version);

                if ($storage === null) {
                    $locked->update(['send_at' => null, 'needs_attention' => true, 'attention_reason' => self::ATTENTION_NO_CANDIDATE_DATE]);
                    $founderAlert = [PlatformInvoiceData::preview($version, $registrantCount, $rateCents, null, 'PENDING', false), PlatformInvoiceMail::VARIANT_NO_CANDIDATE_DATE];

                    return PlatformInvoiceSendOutcome::NoCandidateDate;
                }
            }

            $recipients = $this->recipientsFor($version);

            if ($recipients === []) {
                $locked->update(['send_at' => null, 'needs_attention' => true, 'attention_reason' => self::ATTENTION_NO_RECIPIENTS]);
                $founderAlert = [PlatformInvoiceData::preview($version, $registrantCount, $rateCents, $storage, 'PENDING', false), PlatformInvoiceMail::VARIANT_NO_RECIPIENTS];

                return PlatformInvoiceSendOutcome::NoRecipients;
            }

            $issuedAt = Carbon::now();

            $locked->update([
                'number' => $this->nextNumber($issuedAt),
                'status' => PlatformInvoiceStatus::Sent->value,
                'sent_at' => $issuedAt,
                'issued_at' => $issuedAt,
                'due_at' => $issuedAt->copy()->addDays(PlatformInvoiceData::termsDays()),
                'registrant_count' => $registrantCount,
                'rate_cents' => $rateCents,
                'storage_access_at' => $storage?->accessAt,
                'storage_close_at' => $storage?->closeAt,
                'storage_months' => $storage?->months,
                'storage_rate_cents' => $storage?->rateCents,
                'amount_cents' => $registrantCount * $rateCents + ($storage?->amountCents() ?? 0),
                'recipients' => $recipients,
                'needs_attention' => false,
                'attention_reason' => null,
            ]);

            Mail::to($recipients)
                ->bcc($this->founderEmail())
                ->send(new PlatformInvoiceMail(PlatformInvoiceData::fromInvoice($locked), PlatformInvoiceMail::VARIANT_INVOICE));

            return PlatformInvoiceSendOutcome::Sent;
        });

        if ($founderAlert !== null) {
            [$alertData, $alertVariant] = $founderAlert;
            Mail::to($this->founderEmail())->send(new PlatformInvoiceMail($alertData, $alertVariant));
        }

        return $outcome;
    }

    /**
     * Founder "Resend" of an issued invoice: re-mailed exactly as stored
     * (same number, lines, amount, due date) to the Version's *current*
     * Event Managers. (A Pending needs_attention invoice is sent for the
     * first time with send() instead — see Founder\PlatformInvoices.)
     *
     * @return int number of Event Managers mailed (0 = nothing sent)
     */
    public function resend(VersionPlatformInvoice $invoice): int
    {
        if (! in_array($invoice->statusEnum(), PlatformInvoiceStatus::issuedStates(), true)) {
            return 0;
        }

        $version = Version::withTrashed()->find($invoice->version_id);
        $recipients = $version === null ? [] : $this->recipientsFor($version);

        if ($recipients === []) {
            return 0;
        }

        Mail::to($recipients)
            ->bcc($this->founderEmail())
            ->send(new PlatformInvoiceMail(PlatformInvoiceData::fromInvoice($invoice), PlatformInvoiceMail::VARIANT_RESEND));

        $invoice->update([
            'recipients' => array_values(array_unique([...(array) $invoice->recipients, ...$recipients])),
            'last_resent_at' => Carbon::now(),
            'resend_count' => $invoice->resend_count + 1,
        ]);

        return count($recipients);
    }

    /**
     * Founder "Send test invoice": live numbers, TEST watermark, founder
     * only. Persists nothing. A virtual Version with no Candidate date is
     * previewed without its storage line.
     *
     * @return bool whether the storage line was left off a virtual Version for lack of a Candidate date
     */
    public function sendTest(Version $version): bool
    {
        $storage = $this->isVirtual($version) ? $this->storageCharge($version) : null;

        $data = PlatformInvoiceData::preview(
            $version,
            $this->registrantCount($version),
            $this->rateCents($version),
            $storage,
            'TDR-TEST-PREVIEW',
            true,
        );

        Mail::to($this->founderEmail())->send(new PlatformInvoiceMail($data, PlatformInvoiceMail::VARIANT_TEST));

        return $this->isVirtual($version) && $storage === null;
    }

    /**
     * "Virtual" for billing = Remote auditions AND recorded uploads
     * (Audio/Video); only those Versions store candidate files.
     */
    public function isVirtual(Version $version): bool
    {
        return $version->getRawOriginal('audition_type') === AuditionType::Remote->value
            && in_array($version->getRawOriginal('upload_type'), [UploadType::Audio->value, UploadType::Video->value], true);
    }

    /**
     * File-storage line for a virtual Version, from the Candidate Access Date
     * (Candidate date start_at, one per Version) and the Event Close Date
     * (results_released_at; now when not yet released — Founder previews
     * only). StorageCharge pads each by a month and rounds partial months
     * up. Null when the Version has no Candidate date.
     */
    public function storageCharge(Version $version): ?StorageCharge
    {
        $candidateStart = VersionDate::where('version_id', $version->id)
            ->where('date_type', VersionDateType::Candidate->value)
            ->value('start_at');

        if ($candidateStart === null) {
            return null;
        }

        $releasedAt = Carbon::make($version->getRawOriginal('results_released_at')) ?? Carbon::now();

        return StorageCharge::forDates(
            Carbon::parse((string) $candidateStart),
            $releasedAt,
            (int) config('billing.storage_fee_per_month_cents'),
        );
    }

    public function markPaid(VersionPlatformInvoice $invoice, User $by, CarbonInterface $paidAt, ?string $note): void
    {
        if ($invoice->statusEnum() !== PlatformInvoiceStatus::Sent) {
            throw new RuntimeException('Only a sent invoice can be marked paid.');
        }

        $invoice->update([
            'status' => PlatformInvoiceStatus::Paid->value,
            'paid_at' => $paidAt,
            'paid_by_user_id' => $by->id,
            'payment_note' => filled($note) ? $note : null,
        ]);
    }

    public function markUnpaid(VersionPlatformInvoice $invoice): void
    {
        if ($invoice->statusEnum() !== PlatformInvoiceStatus::Paid) {
            throw new RuntimeException('Only a paid invoice can be marked unpaid.');
        }

        $invoice->update([
            'status' => PlatformInvoiceStatus::Sent->value,
            'paid_at' => null,
            'paid_by_user_id' => null,
            'payment_note' => null,
        ]);
    }

    /**
     * Every registrant counts — CandidateStatus::registrantStates(), not
     * status=Registered (which drains once results are released). Fee
     * waivers are deliberately ignored.
     */
    public function registrantCount(Version $version): int
    {
        return Candidate::where('version_id', $version->id)
            ->whereIn('status', CandidateStatus::registrantStates())
            ->count();
    }

    public function rateCents(Version $version): int
    {
        $override = $version->event?->platform_fee_per_registrant_cents;

        return $override ?? (int) config('billing.platform_fee_per_registrant_cents');
    }

    /**
     * Event Managers of *this* Version only — not eventManagersForEvent(),
     * which spans every Version of the Event.
     *
     * @return list<string>
     */
    public function recipientsFor(Version $version): array
    {
        return ($this->roles->assignmentsForVersion($version)->get('Event Manager') ?? collect())
            ->pluck('email')
            ->filter()
            ->map(fn (mixed $email): string => (string) $email)
            ->unique()
            ->values()
            ->all();
    }

    public function founderEmail(): string
    {
        return (string) config('billing.founder_email');
    }

    private function sendAtFrom(Carbon $clickedAt): Carbon
    {
        return $clickedAt->copy()->addHours((int) config('billing.send_delay_hours', 24));
    }

    /**
     * TDR-{YYYY}-{NNNN}, sequential within the issue year (America/New_York).
     * Called inside send()'s transaction; the unique index on number is the
     * final guard against a concurrent collision.
     */
    private function nextNumber(CarbonInterface $issuedAt): string
    {
        $prefix = 'TDR-'.$issuedAt->copy()->forDisplay()->format('Y').'-';

        $last = VersionPlatformInvoice::where('number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('number')
            ->value('number');

        $next = $last === null ? 1 : ((int) substr((string) $last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
