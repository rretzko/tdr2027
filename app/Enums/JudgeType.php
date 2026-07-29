<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A single flexible set covering both in-person and remote judging-role
 * vocabulary — not validated against a Version's AuditionType. Room, and
 * therefore judge assignment, applies identically regardless of whether
 * the Candidate is physically present (see event-version-orientation.md §5.9).
 */
enum JudgeType: string
{
    case HeadJudge = 'head_judge';
    case LeadJudge = 'lead_judge';
    case Judge1 = 'judge_1';
    case Judge2 = 'judge_2';
    case Judge3 = 'judge_3';
    case Judge4 = 'judge_4';
    case JudgeMonitor = 'judge_monitor';
    case Monitor = 'monitor';

    public function label(): string
    {
        return match ($this) {
            self::HeadJudge => 'Head Judge',
            self::LeadJudge => 'Lead Judge',
            self::Judge1 => 'Judge 1',
            self::Judge2 => 'Judge 2',
            self::Judge3 => 'Judge 3',
            self::Judge4 => 'Judge 4',
            self::JudgeMonitor => 'Judge Monitor',
            self::Monitor => 'Monitor',
        };
    }
}
