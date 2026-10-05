<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EventStatus;
use App\Mail\MondayMorningScorecardMail;
use App\Models\Version;
use App\Models\VersionScorecardSnapshot;
use App\Services\VersionRoleAssignmentService;
use App\Services\VersionScorecardService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Every Monday morning, emails each Active Version's Event Managers a
 * "Monday Morning Scorecard" summarizing invitation, obligation,
 * registration, and registration-fee progress. Scoped per-Version (not
 * Event-wide via VersionRoleAssignmentService::eventManagersForEvent()) so a
 * manager assigned to one Version of an Event doesn't get scorecards for a
 * sibling Version they have no role on.
 *
 * Also records a VersionScorecardSnapshot for every Active Version, including
 * ones with no Event Manager to email, so the weekly history has no gaps.
 */
class SendMondayMorningScorecardEmails extends Command
{
    protected $signature = 'versions:send-monday-morning-scorecard';

    protected $description = "Email each Active Version's Event Managers a Monday Morning Scorecard of invitation, obligation, registration, and fee progress.";

    public function handle(VersionRoleAssignmentService $roles, VersionScorecardService $scorecard): int
    {
        $versions = Version::query()
            ->where('status', EventStatus::Active->value)
            ->with('event')
            ->get();

        $emailsSent = 0;
        $capturedOn = now(config('app.display_timezone'));

        foreach ($versions as $version) {
            $metrics = $scorecard->metricsFor($version);

            VersionScorecardSnapshot::record($version, $metrics, $capturedOn);

            $eventManagers = $roles->assignmentsForVersion($version)->get('Event Manager') ?? collect();

            foreach ($eventManagers as $eventManager) {
                if ($eventManager->email === null) {
                    continue;
                }

                Mail::to($eventManager->email)->send(new MondayMorningScorecardMail($version, $metrics));

                $emailsSent++;
            }
        }

        if ($emailsSent > 0) {
            Log::info('SendMondayMorningScorecardEmails: sent Monday Morning Scorecard emails', [
                'versions' => $versions->count(),
                'emails_sent' => $emailsSent,
            ]);
        }

        $this->info("Sent Monday Morning Scorecard emails for {$versions->count()} active version(s) ({$emailsSent} email(s) sent).");

        return self::SUCCESS;
    }
}
