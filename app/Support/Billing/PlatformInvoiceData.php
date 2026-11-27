<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Version;
use App\Models\VersionPlatformInvoice;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The one shape the platform invoice PDF, its email and the Founder screen
 * all render from — built either from a stored invoice's send-time snapshot
 * (fromInvoice) or from a Version's live numbers (preview, for Founder test
 * sends and the needs-attention founder alerts).
 *
 * Lines: registrants × rate, plus — for virtual Versions only — file storage
 * months × monthly rate ($storage). amountCents is the invoice total.
 * $storageIncluded marks a virtual Version billed at an all-in rate: no
 * storage line, a note under the registrant line instead.
 */
final readonly class PlatformInvoiceData
{
    public int $registrantAmountCents;

    public int $amountCents;

    /**
     * @param  array{name: string, address_lines: list<string>, payable_to: string}  $company
     */
    public function __construct(
        public string $number,
        public bool $isTest,
        public CarbonInterface $issuedAt,
        public CarbonInterface $dueAt,
        public int $registrantCount,
        public int $rateCents,
        public ?StorageCharge $storage,
        public string $organizationName,
        public string $eventName,
        public string $versionName,
        public array $company,
        public bool $storageIncluded = false,
    ) {
        $this->registrantAmountCents = $registrantCount * $rateCents;
        $this->amountCents = $this->registrantAmountCents + ($storage?->amountCents() ?? 0);
    }

    public static function fromInvoice(VersionPlatformInvoice $invoice): self
    {
        $version = $invoice->version()->withTrashed()->firstOrFail();
        [$organizationName, $eventName] = self::names($version);
        $issuedAt = $invoice->issued_at ?? Carbon::now();

        $storage = $invoice->storage_access_at !== null && $invoice->storage_close_at !== null && $invoice->storage_months !== null
            ? new StorageCharge($invoice->storage_access_at, $invoice->storage_close_at, $invoice->storage_months, (int) $invoice->storage_rate_cents)
            : null;

        return new self(
            number: (string) $invoice->number,
            isTest: false,
            issuedAt: $issuedAt,
            dueAt: $invoice->due_at ?? $issuedAt->copy()->addDays(self::termsDays()),
            registrantCount: (int) $invoice->registrant_count,
            rateCents: (int) $invoice->rate_cents,
            storage: $storage,
            organizationName: $organizationName,
            eventName: $eventName,
            versionName: $version->name,
            company: self::company(),
            storageIncluded: $invoice->storage_included,
        );
    }

    public static function preview(Version $version, int $registrantCount, int $rateCents, ?StorageCharge $storage, string $number, bool $isTest, bool $storageIncluded): self
    {
        [$organizationName, $eventName] = self::names($version);
        $issuedAt = Carbon::now();

        return new self(
            number: $number,
            isTest: $isTest,
            issuedAt: $issuedAt,
            dueAt: $issuedAt->copy()->addDays(self::termsDays()),
            registrantCount: $registrantCount,
            rateCents: $rateCents,
            storage: $storage,
            organizationName: $organizationName,
            eventName: $eventName,
            versionName: $version->name,
            company: self::company(),
            storageIncluded: $storageIncluded,
        );
    }

    public static function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }

    public static function termsDays(): int
    {
        return (int) config('billing.terms_days', 30);
    }

    /**
     * Bill-to names, tolerant of a soft-deleted Event or one with no
     * Organization (falls back to the Event name).
     *
     * @return array{0: string, 1: string}
     */
    private static function names(Version $version): array
    {
        $event = Event::withTrashed()->find($version->event_id);
        $eventName = (string) $event?->name;
        $organizationName = $event === null ? null : Organization::whereKey($event->organization_id)->value('name');

        return [filled($organizationName) ? (string) $organizationName : $eventName, $eventName];
    }

    /**
     * @return array{name: string, address_lines: list<string>, payable_to: string}
     */
    private static function company(): array
    {
        /** @var array{name: string, address_lines: list<string>, payable_to: string} $company */
        $company = config('billing.company');

        return $company;
    }
}
