<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Candidate;
use App\Models\VoicePart;
use Illuminate\Support\Collection;

/**
 * One Candidate on the Voice Part ↔ Recording audit (VoicePartAuditService).
 */
final readonly class VoicePartAuditRow
{
    /**
     * @param  Collection<int, VoicePart>  $offered  voice parts this Candidate's grade is offered
     */
    public function __construct(
        public Candidate $candidate,
        public ?int $grade,
        public Collection $offered,
        public bool $isOffered,
    ) {}
}
