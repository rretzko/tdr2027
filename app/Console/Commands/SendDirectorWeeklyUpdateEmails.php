<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EventStatus;
use App\Enums\VersionInvitationStatus;
use App\Mail\DirectorWeeklyUpdateMail;
use App\Models\VersionInvitation;
use App\Services\DirectorWeeklyUpdateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Every Tuesday morning, emails each director with an Invited, Obligated, or
 * Participating version_invitations row on an Active Version a participation
 * update on their students' progress. Rejected invitations are skipped.
 *
 * One failed send is logged and skipped rather than aborting the run, so a
 * single bad address can't stop every other director's update.
 */
class SendDirectorWeeklyUpdateEmails extends Command
{
    protected $signature = 'versions:send-director-weekly-update';

    protected $description = 'Email each invited/obligated/participating director of an Active Version a weekly update on their students\' registration progress.';

    public function handle(DirectorWeeklyUpdateService $updates): int
    {
        $invitations = VersionInvitation::query()
            ->whereIn('status', [
                VersionInvitationStatus::Invited->value,
                VersionInvitationStatus::Obligated->value,
                VersionInvitationStatus::Participating->value,
            ])
            ->whereHas('version', fn ($q) => $q->where('status', EventStatus::Active->value))
            ->with(['version.event', 'version.fees', 'teacher.user'])
            ->get();

        $emailsSent = 0;
        $failures = 0;

        foreach ($invitations as $invitation) {
            $email = $invitation->teacher->user?->email;

            if ($email === null || $email === '') {
                continue;
            }

            try {
                Mail::to($email)->send(new DirectorWeeklyUpdateMail($updates->updateFor($invitation)));
                $emailsSent++;
            } catch (Throwable $e) {
                $failures++;
                Log::warning('SendDirectorWeeklyUpdateEmails: failed to send weekly update', [
                    'version_invitation_id' => $invitation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('SendDirectorWeeklyUpdateEmails: sent director weekly update emails', [
            'invitations' => $invitations->count(),
            'emails_sent' => $emailsSent,
            'failures' => $failures,
        ]);

        $this->info("Sent {$emailsSent} director weekly update email(s) for {$invitations->count()} invitation(s) ({$failures} failure(s)).");

        return self::SUCCESS;
    }
}
