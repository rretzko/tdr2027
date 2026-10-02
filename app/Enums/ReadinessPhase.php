<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * When a Version readiness item must be complete — grouped by lifecycle
 * moment rather than by VersionEdit tab (docs/plans/version-readiness.md §2.2).
 * Case order is the order phases occur in.
 */
enum ReadinessPhase: string
{
    case Structure = 'structure';
    case BeforeInvitations = 'before_invitations';
    case BeforeRegistration = 'before_registration';
    case BeforeAuditions = 'before_auditions';
    case BeforeResults = 'before_results';

    public function label(): string
    {
        return match ($this) {
            self::Structure => 'Event structure',
            self::BeforeInvitations => 'Before inviting teachers',
            self::BeforeRegistration => 'Before registration opens',
            self::BeforeAuditions => 'Before auditions',
            self::BeforeResults => 'Before releasing results',
        };
    }

    public function position(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
