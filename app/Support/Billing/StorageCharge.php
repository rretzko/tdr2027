<?php

declare(strict_types=1);

namespace App\Support\Billing;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The virtual-audition file-storage line of a platform invoice. Built from
 * two source dates — the Candidate Access Date (version_dates
 * date_type=candidate start_at) and the Event Close Date
 * (versions.results_released_at) — padded by one month before and after
 * for testing and post-event review. Billed per month, partial months
 * rounded up.
 *
 * The source dates (not the padded window) are what's stored, because the
 * invoice prints them verbatim and no-overflow month shifts aren't
 * reversible (Mar 31 − 1 month = Feb 28, + 1 month = Mar 28).
 */
final readonly class StorageCharge
{
    public function __construct(
        public CarbonInterface $accessAt,
        public CarbonInterface $closeAt,
        public int $months,
        public int $rateCents,
    ) {}

    public static function forDates(CarbonInterface $accessAt, CarbonInterface $closeAt, int $rateCents): self
    {
        return new self($accessAt, $closeAt, self::monthsRoundedUp(self::padStart($accessAt), self::padEnd($closeAt)), $rateCents);
    }

    /**
     * Storage begins one month before the Candidate Access Date.
     */
    public function startsAt(): CarbonInterface
    {
        return self::padStart($this->accessAt);
    }

    /**
     * Storage ends one month after the Event Close Date.
     */
    public function endsAt(): CarbonInterface
    {
        return self::padEnd($this->closeAt);
    }

    /**
     * Smallest whole number of months that covers the window (any partial
     * month counts as a full one), minimum 1. Uses no-overflow month math, so
     * Jan 31 + 1 month is Feb 28/29, not Mar 3.
     */
    public static function monthsRoundedUp(CarbonInterface $startsAt, CarbonInterface $endsAt): int
    {
        $months = 1;

        while (Carbon::instance($startsAt)->addMonthsNoOverflow($months)->lessThan($endsAt)) {
            $months++;
        }

        return $months;
    }

    public function amountCents(): int
    {
        return $this->months * $this->rateCents;
    }

    private static function padStart(CarbonInterface $accessAt): CarbonInterface
    {
        return Carbon::instance($accessAt)->subMonthNoOverflow();
    }

    private static function padEnd(CarbonInterface $closeAt): CarbonInterface
    {
        return Carbon::instance($closeAt)->addMonthNoOverflow();
    }
}
