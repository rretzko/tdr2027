<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Distinct from VersionInvitationStatus: a request row does not represent
 * an invitation, it represents a teacher asking for one. Approval writes
 * the actual VersionInvitation row separately (see §5.8) rather than this
 * enum being read as the invitation state itself. Denied is not terminal —
 * re-requesting resets the same row back to Pending rather than blocking.
 */
enum VersionInvitationRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Denied => 'Denied',
        };
    }
}
