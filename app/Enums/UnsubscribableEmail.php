<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Scheduled digest emails a recipient can opt out of. An unsubscribe applies to
 * that email type across every Event/Version until the user resubscribes from
 * Settings → Email Preferences.
 */
enum UnsubscribableEmail: string
{
    case DirectorWeeklyUpdate = 'director_weekly_update';
    case MondayScorecard = 'monday_scorecard';

    public function label(): string
    {
        return match ($this) {
            self::DirectorWeeklyUpdate => 'Director Weekly Update',
            self::MondayScorecard => 'Monday Morning Scorecard',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DirectorWeeklyUpdate => "Wednesday morning summary of your students' registration progress for each event you're invited to.",
            self::MondayScorecard => "Monday morning summary of an event's invitation, registration, and fee progress, sent to Event Managers.",
        };
    }
}
