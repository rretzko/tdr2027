<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A manager's explicit confirmation of a readiness item (Acknowledged), or
 * a clone-time flag that a carried-over value must be looked at again
 * before it counts as done (ReviewRequired).
 */
enum ReadinessReviewState: string
{
    case Acknowledged = 'acknowledged';
    case ReviewRequired = 'review_required';
}
