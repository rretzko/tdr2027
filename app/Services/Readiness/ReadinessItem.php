<?php

declare(strict_types=1);

namespace App\Services\Readiness;

use App\Enums\ReadinessEditor;
use App\Enums\ReadinessPhase;
use App\Enums\ReadinessStatus;
use App\Models\Version;
use Closure;

/**
 * One decision (or tightly related group of decisions) a manager must make
 * to configure a Version. Items are read-only and side-effect free — they
 * only inspect a pre-loaded ReadinessContext. See ReadinessCatalog for the
 * full list and docs/plans/version-readiness.md §2.4.
 */
final readonly class ReadinessItem
{
    /**
     * @param  Closure(ReadinessContext): ReadinessStatus  $resolve  data-derived status; NeedsReview means "a usable value exists but nobody confirmed it"
     * @param  Closure(Version): string  $url  deep link to where the decision is made
     * @param  (Closure(ReadinessContext): bool)|null  $applicable  null = always applicable
     * @param  (Closure(ReadinessContext): ?string)|null  $detail  short progress note, e.g. "3 of 4 rooms staffed"
     * @param  bool  $blocking  participates in phase gates (e.g. Sandbox → Active)
     * @param  bool  $acknowledgeable  can be confirmed with "Looks right" at any time
     * @param  bool  $yearSensitive  starts as review-required on a cloned Version (§9a Q4)
     * @param  ?string  $section  VersionEdit tab whose save counts as reviewing this item
     * @param  list<string>  $covers  "table.column" fields this item accounts for (coverage test)
     * @param  ReadinessEditor  $editor  who may change the setting — must match the gate on $url's page
     */
    public function __construct(
        public string $key,
        public ReadinessPhase $phase,
        public string $question,
        public string $why,
        public Closure $resolve,
        public Closure $url,
        public ?Closure $applicable = null,
        public ?Closure $detail = null,
        public bool $blocking = true,
        public bool $acknowledgeable = false,
        public bool $yearSensitive = false,
        public ?string $section = null,
        public array $covers = [],
        public ReadinessEditor $editor = ReadinessEditor::EventManager,
    ) {}

    public function isApplicable(ReadinessContext $context): bool
    {
        return $this->applicable === null || ($this->applicable)($context);
    }

    public function baseStatus(ReadinessContext $context): ReadinessStatus
    {
        return ($this->resolve)($context);
    }

    public function detailFor(ReadinessContext $context): ?string
    {
        return $this->detail === null ? null : ($this->detail)($context);
    }

    public function urlFor(Version $version): string
    {
        return ($this->url)($version);
    }
}
