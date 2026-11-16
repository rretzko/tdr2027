<?php

declare(strict_types=1);

namespace App\Livewire\Founder;

use App\Enums\EventStatus;
use App\Enums\PlatformInvoiceSendOutcome;
use App\Enums\PlatformInvoiceStatus;
use App\Models\Event;
use App\Models\Version;
use App\Models\VersionPlatformInvoice;
use App\Services\PlatformInvoiceService;
use App\Support\Billing\PlatformInvoiceData;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Founder screen for TDR's platform-usage invoices
 * (docs/plans/close-audition-invoice.md): list, mark paid/unpaid, resend,
 * test send (founder only) and per-Event rate overrides. Guarded actions
 * stay visible on every row and explain themselves in a toast when they
 * don't apply.
 */
#[Layout('components.layouts.app')]
class PlatformInvoices extends Component
{
    public string $statusFilter = '';

    public string $search = '';

    public ?int $payingInvoiceId = null;

    public string $paidOn = '';

    public string $paymentNote = '';

    public ?int $resendInvoiceId = null;

    public ?int $testVersionId = null;

    /**
     * Event id => rate in dollars as typed ('' = use the default).
     *
     * @var array<int, string>
     */
    public array $rates = [];

    /**
     * @return Collection<int, VersionPlatformInvoice>
     */
    public function invoices(): Collection
    {
        $query = VersionPlatformInvoice::query()
            ->with(['version' => fn ($q) => $q->withTrashed()->with(['event' => fn ($e) => $e->withTrashed()])]);

        if ($this->statusFilter === 'overdue') {
            $query->where('status', PlatformInvoiceStatus::Sent->value)->where('due_at', '<', Carbon::now());
        } elseif ($this->statusFilter === 'attention') {
            $query->where('needs_attention', true);
        } elseif ($this->statusFilter !== '' && PlatformInvoiceStatus::tryFrom($this->statusFilter) !== null) {
            $query->where('status', $this->statusFilter);
        }

        if (filled($this->search)) {
            $search = $this->search;

            $query->where(function ($outer) use ($search): void {
                $outer->where('number', 'like', "%{$search}%")
                    ->orWhereHas('version', fn ($v) => $v->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('version.event', fn ($e) => $e->where('name', 'like', "%{$search}%"));
            });
        }

        return $query
            ->orderByRaw('COALESCE(sent_at, send_at, created_at) DESC')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array{outstanding: int, overdue: int, paidThisYear: int}
     */
    public function totals(): array
    {
        $startOfYear = Carbon::now()->forDisplay()->startOfYear()->utc();

        return [
            'outstanding' => (int) VersionPlatformInvoice::where('status', PlatformInvoiceStatus::Sent->value)->sum('amount_cents'),
            'overdue' => (int) VersionPlatformInvoice::where('status', PlatformInvoiceStatus::Sent->value)->where('due_at', '<', Carbon::now())->sum('amount_cents'),
            'paidThisYear' => (int) VersionPlatformInvoice::where('status', PlatformInvoiceStatus::Paid->value)->where('paid_at', '>=', $startOfYear)->sum('amount_cents'),
        ];
    }

    public function openMarkPaid(int $id): void
    {
        $invoice = VersionPlatformInvoice::findOrFail($id);

        if ($invoice->statusEnum() !== PlatformInvoiceStatus::Sent) {
            Flux::toast(text: $this->notPayableMessage($invoice), variant: 'warning');

            return;
        }

        $this->resetErrorBag();
        $this->payingInvoiceId = $invoice->id;
        $this->paidOn = Carbon::now()->forDisplay()->toDateString();
        $this->paymentNote = '';
        $this->modal('mark-paid')->show();
    }

    public function markPaid(PlatformInvoiceService $invoices): void
    {
        $this->validate([
            'paidOn' => ['required', 'date', 'before_or_equal:tomorrow'],
            'paymentNote' => ['nullable', 'string', 'max:255'],
        ], [], ['paidOn' => 'paid date', 'paymentNote' => 'note']);

        $invoice = VersionPlatformInvoice::findOrFail($this->payingInvoiceId);

        if ($invoice->statusEnum() !== PlatformInvoiceStatus::Sent) {
            $this->modal('mark-paid')->close();
            Flux::toast(text: $this->notPayableMessage($invoice), variant: 'warning');

            return;
        }

        // Noon display-time, so the stored UTC instant never slips to the
        // neighbouring calendar day.
        $paidAt = Carbon::parse($this->paidOn.' 12:00', (string) config('app.display_timezone'))->utc();

        $invoices->markPaid($invoice, Auth::user(), $paidAt, trim($this->paymentNote));

        $this->modal('mark-paid')->close();
        $this->payingInvoiceId = null;

        Flux::toast(text: "Invoice {$invoice->number} marked paid.", variant: 'success');
    }

    public function markUnpaid(int $id, PlatformInvoiceService $invoices): void
    {
        $invoice = VersionPlatformInvoice::findOrFail($id);

        if ($invoice->statusEnum() !== PlatformInvoiceStatus::Paid) {
            Flux::toast(text: 'Only a paid invoice can be marked unpaid.', variant: 'warning');

            return;
        }

        $invoices->markUnpaid($invoice);

        Flux::toast(text: "Invoice {$invoice->number} marked unpaid.", variant: 'success');
    }

    public function openResend(int $id): void
    {
        $invoice = VersionPlatformInvoice::findOrFail($id);
        $status = $invoice->statusEnum();

        $resendable = in_array($status, PlatformInvoiceStatus::issuedStates(), true)
            || ($status === PlatformInvoiceStatus::Pending && $invoice->needs_attention);

        if (! $resendable) {
            Flux::toast(text: match ($status) {
                PlatformInvoiceStatus::Void => 'This invoice was voided ('.($invoice->voided_reason ?? 'no reason recorded').'), so there is nothing to resend.',
                default => 'Not sent yet, so there is nothing to resend.',
            }, variant: 'warning');

            return;
        }

        $this->resendInvoiceId = $invoice->id;
        $this->modal('resend-invoice')->show();
    }

    public function resend(PlatformInvoiceService $invoices): void
    {
        $invoice = VersionPlatformInvoice::with('version')->findOrFail($this->resendInvoiceId);
        $versionName = $invoice->version?->name;

        $this->modal('resend-invoice')->close();
        $this->resendInvoiceId = null;

        // A needs-attention Pending invoice gets its first real send, which
        // re-runs every check (closed? registrants? Candidate date? EMs?).
        if ($invoice->statusEnum() === PlatformInvoiceStatus::Pending) {
            $outcome = $invoices->send($invoice);
            $fresh = $invoice->fresh();
            $sentTo = count((array) $fresh?->recipients);

            [$text, $variant] = match ($outcome) {
                PlatformInvoiceSendOutcome::Sent => ["Invoice {$fresh?->number} sent to {$sentTo} Event ".str('Manager')->plural($sentTo).'.', 'success'],
                PlatformInvoiceSendOutcome::NoRecipients => ["{$versionName} still has no Event Managers. Assign one, then try again.", 'danger'],
                PlatformInvoiceSendOutcome::NoCandidateDate => ["{$versionName} still has no Candidate date, which the storage line needs. Add it on the Dates tab, then try again.", 'danger'],
                PlatformInvoiceSendOutcome::Voided => ["{$versionName} has no registrants, so the invoice was voided.", 'warning'],
                PlatformInvoiceSendOutcome::Held => ["{$versionName} is no longer closed, so the invoice is on hold until it is closed again.", 'warning'],
                PlatformInvoiceSendOutcome::Skipped => ['This invoice is no longer waiting to be sent.', 'warning'],
            };

            Flux::toast(text: $text, variant: $variant);

            return;
        }

        $sentTo = $invoices->resend($invoice);

        if ($sentTo === 0) {
            Flux::toast(text: "{$versionName} has no Event Managers to send to. Assign one, then try again.", variant: 'danger');

            return;
        }

        Flux::toast(text: "Invoice {$invoice->number} resent to {$sentTo} Event ".str('Manager')->plural($sentTo).'.', variant: 'success');
    }

    public function openTest(): void
    {
        $this->resetErrorBag();
        $this->testVersionId = null;
        $this->modal('test-invoice')->show();
    }

    public function sendTest(PlatformInvoiceService $invoices): void
    {
        $this->validate(['testVersionId' => ['required', 'integer', 'exists:versions,id']], [], ['testVersionId' => 'version']);

        $version = Version::with('event')->findOrFail($this->testVersionId);

        $storageOmitted = $invoices->sendTest($version);

        $this->modal('test-invoice')->close();

        $note = $storageOmitted ? ' It has no storage line, because this virtual Version has no Candidate date. A real send would be held.' : '';

        Flux::toast(text: "Test invoice for {$version->name} sent to {$invoices->founderEmail()}.{$note}", variant: $storageOmitted ? 'warning' : 'success');
    }

    public function openRates(): void
    {
        $this->resetErrorBag();
        $this->rates = Event::orderBy('name')->get()
            ->mapWithKeys(fn (Event $event): array => [
                $event->id => $event->platform_fee_per_registrant_cents === null
                    ? ''
                    : number_format($event->platform_fee_per_registrant_cents / 100, 2, '.', ''),
            ])
            ->all();

        $this->modal('event-rates')->show();
    }

    public function saveRates(): void
    {
        $this->validate(
            ['rates.*' => ['nullable', 'numeric', 'min:0', 'max:1000']],
            ['rates.*.numeric' => 'Each rate must be a dollar amount.', 'rates.*.max' => 'A rate cannot exceed $1,000.', 'rates.*.min' => 'A rate cannot be negative.'],
        );

        $changed = 0;

        foreach (Event::whereIn('id', array_keys($this->rates))->get() as $event) {
            $typed = trim((string) ($this->rates[$event->id] ?? ''));
            $cents = $typed === '' ? null : (int) round(((float) $typed) * 100);

            if ($event->platform_fee_per_registrant_cents !== $cents) {
                $event->update(['platform_fee_per_registrant_cents' => $cents]);
                $changed++;
            }
        }

        $this->modal('event-rates')->close();

        Flux::toast(text: $changed === 0 ? 'No rate changes to save.' : "Saved per-registrant rates for {$changed} ".str('event')->plural($changed).'.', variant: 'success');
    }

    public function render(): View
    {
        $resending = $this->resendInvoiceId === null
            ? null
            : VersionPlatformInvoice::with('version')->find($this->resendInvoiceId);

        return view('livewire.founder.platform-invoices', [
            'invoices' => $this->invoices(),
            'totals' => $this->totals(),
            'statuses' => PlatformInvoiceStatus::cases(),
            'resending' => $resending,
            'resendRecipients' => $resending?->version === null ? [] : app(PlatformInvoiceService::class)->recipientsFor($resending->version),
            'testVersions' => Version::with('event')
                ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [EventStatus::Closed->value])
                ->orderByDesc('id')
                ->get(),
            'rateEvents' => Event::orderBy('name')->get(),
            'defaultRate' => PlatformInvoiceData::money((int) config('billing.platform_fee_per_registrant_cents')),
        ]);
    }

    private function notPayableMessage(VersionPlatformInvoice $invoice): string
    {
        return match ($invoice->statusEnum()) {
            PlatformInvoiceStatus::Paid => "Invoice {$invoice->number} is already marked paid.",
            PlatformInvoiceStatus::Void => 'A voided invoice cannot be marked paid.',
            default => 'Not sent yet. Only a sent invoice can be marked paid.',
        };
    }
}
