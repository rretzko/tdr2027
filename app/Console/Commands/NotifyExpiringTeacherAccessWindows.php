<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EventStatus;
use App\Enums\VersionDateType;
use App\Mail\TeacherAccessWindowExpiringMail;
use App\Models\VersionDate;
use App\Services\VersionRoleAssignmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * A Version's teacher access window (version_dates.date_type=teacher) opens
 * on a schedule that's independent of the Version's own status — a Version
 * left in Sandbox/Inactive/Closed when its teacher window starts silently
 * locks teachers out of the event pages with no warning to anyone
 * responsible. This nags that Version's Event Managers daily, starting five
 * days before the window opens and stopping five days after it opens (same
 * --days value both ways), for as long as the Version stays non-Active
 * within that span.
 *
 * The lower bound (start_at within the last --days) caps how long a
 * forgotten Version keeps generating email — past that grace period it's
 * assumed someone's aware and further nagging just adds noise. Teacher rows
 * commonly have no end_at at all (see VersionDateType::hasEndAt()), so a
 * null end_at is treated as "still open" rather than excluded.
 */
class NotifyExpiringTeacherAccessWindows extends Command
{
    protected $signature = 'versions:notify-expiring-teacher-access {--days=5 : Days before the teacher access window opens to start notifying, and days after it opens to stop}';

    protected $description = "Email a Version's Event Managers when its teacher access window is about to open (or opened within the last --days) while the Version is still not Active.";

    public function handle(VersionRoleAssignmentService $roles): int
    {
        $days = (int) $this->option('days');

        $versionDates = VersionDate::query()
            ->where('date_type', VersionDateType::Teacher)
            ->whereBetween('start_at', [now()->subDays($days), now()->addDays($days)])
            ->where(fn ($query) => $query->whereNull('end_at')->orWhere('end_at', '>', now()))
            ->whereHas('version', fn ($query) => $query->where('status', '!=', EventStatus::Active->value))
            ->with('version.event')
            ->get();

        $emailsSent = 0;

        foreach ($versionDates as $versionDate) {
            $version = $versionDate->version;

            $configureUrl = URL::route('events.versions.edit', $version);

            foreach ($roles->eventManagersForEvent($version->event) as $eventManager) {
                if ($eventManager->email === null) {
                    continue;
                }

                Mail::to($eventManager->email)->send(new TeacherAccessWindowExpiringMail($version, $configureUrl));

                $emailsSent++;
            }
        }

        if ($emailsSent > 0) {
            Log::info('NotifyExpiringTeacherAccessWindows: notified Event Managers of expiring teacher access windows', [
                'versions' => $versionDates->count(),
                'emails_sent' => $emailsSent,
            ]);
        }

        $this->info("Notified Event Managers about {$versionDates->count()} version(s) with an expiring teacher access window ({$emailsSent} email(s) sent).");

        return self::SUCCESS;
    }
}
