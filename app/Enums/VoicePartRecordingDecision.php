<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What happens to a Candidate's existing recordings when their voice part
 * changes (VoicePartChangeService).
 */
enum VoicePartRecordingDecision: string
{
    /** The recordings are of the new part; the old part was the mistake. */
    case Match = 'match';

    /** The recordings are of another part; delete them. */
    case Remove = 'remove';

    /** Student self-service: keep pending recordings under the old part until they're replaced. */
    case Replace = 'replace';

    /**
     * The answers a teacher or event staff member may give.
     *
     * @return list<string>
     */
    public static function answers(): array
    {
        return [self::Match->value, self::Remove->value];
    }
}
