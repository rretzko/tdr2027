<?php

declare(strict_types=1);

namespace App\Support\Adjudication;

use Illuminate\Support\Carbon;

/**
 * One judge's scores for one Candidate, collapsed to a single save event —
 * see AdjudicationProgressService for why that's a fair reading of the
 * scores timestamps.
 */
final readonly class JudgeSave
{
    public function __construct(
        public int $judgeId,
        public int $candidateId,
        public int $factorsEntered,
        public Carbon $firstAt,
        public Carbon $lastAt,
    ) {}
}
