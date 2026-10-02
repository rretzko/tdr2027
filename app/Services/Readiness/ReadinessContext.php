<?php

declare(strict_types=1);

namespace App\Services\Readiness;

use App\Enums\ApplicationType;
use App\Enums\VersionDateType;
use App\Models\Ensemble;
use App\Models\EventEpaymentConfig;
use App\Models\ScoreCategory;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionReadinessReview;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Everything every ReadinessItem needs, loaded once per evaluation so the
 * ~40 items never issue their own queries (no N+1).
 */
final readonly class ReadinessContext
{
    /**
     * @param  Collection<int, Ensemble>  $ensembles  with grades + voiceParts loaded
     * @param  Collection<int, ScoreCategory>  $rubric  resolved categories, with score_factors_count
     * @param  Collection<string, covariant Collection<int, User>>  $roles  version-scoped role name => holders (read-only)
     * @param  int  $invitedTeacherCount  invitations excluding the Version's own role holders
     * @param  Collection<string, VersionReadinessReview>  $reviews  keyed by item_key
     */
    public function __construct(
        public Version $version,
        public Collection $ensembles,
        public Collection $rubric,
        public Collection $roles,
        public ?EventEpaymentConfig $epaymentConfig,
        public int $invitedTeacherCount,
        public bool $registrationManagerHasMailTo,
        public Collection $reviews,
    ) {}

    public function raw(string $attribute): mixed
    {
        return $this->version->getRawOriginal($attribute);
    }

    public function date(VersionDateType $type): ?VersionDate
    {
        return $this->version->dates->first(
            fn (VersionDate $date): bool => $date->getRawOriginal('date_type') === $type->value,
        );
    }

    /**
     * Read raw rather than via the datetime cast — Larastan doesn't see
     * method-based casts() (see memory: PHPStan quirks).
     */
    public function startDate(VersionDateType $type): ?CarbonInterface
    {
        $raw = $this->date($type)?->getRawOriginal('start_at');

        return $raw === null ? null : Carbon::parse($raw);
    }

    /**
     * A date counts as set when it has a start, plus an end for the types
     * whose window has one (VersionDateType::hasEndAt()).
     */
    public function dateIsSet(VersionDateType $type): bool
    {
        $date = $this->date($type);

        if ($date === null || $date->getRawOriginal('start_at') === null) {
            return false;
        }

        return ! $type->hasEndAt() || $date->getRawOriginal('end_at') !== null;
    }

    /**
     * @return Collection<int, User>
     */
    public function roleHolders(string $role): Collection
    {
        return $this->roles->get($role, collect());
    }

    public function isPdfApplication(): bool
    {
        return $this->raw('application_type') === ApplicationType::Pdf->value;
    }

    /**
     * Teachers mail physical materials (applications, checks, cards) —
     * independent of application_type; see versions.mail_required.
     */
    public function mailRequired(): bool
    {
        return (bool) $this->raw('mail_required');
    }

    public function epaymentEnabled(): bool
    {
        $config = $this->version->versionEpaymentConfig;

        return $config !== null && ($config->epayment_student || $config->epayment_teacher);
    }
}
