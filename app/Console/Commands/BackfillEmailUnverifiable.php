<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\EmailVerifiabilityChecker;
use Illuminate\Console\Command;

/**
 * One-time backfill for accounts created before EmailVerifiabilityChecker
 * recognized their email's domain pattern (e.g. wmrhsd.org, 2026-09-10 —
 * the checker only ever runs once, at self-registration time, so a later
 * heuristic fix never reaches existing rows on its own).
 */
class BackfillEmailUnverifiable extends Command
{
    protected $signature = 'users:backfill-email-unverifiable {--force : Skip confirmation prompt}';

    protected $description = 'Flag existing users as email_unverifiable if their email domain now matches EmailVerifiabilityChecker';

    public function handle(): int
    {
        $matches = User::where('email_unverifiable', false)
            ->get(['id', 'name', 'email'])
            ->filter(fn (User $user): bool => EmailVerifiabilityChecker::isLikelyUnverifiable($user->email))
            ->values();

        if ($matches->isEmpty()) {
            $this->info('No matching users found — nothing to do.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Email'],
            $matches->map(fn (User $user): array => [$user->id, $user->name, $user->email])->all()
        );

        $this->info("{$matches->count()} user(s) match and will be set to email_unverifiable = true.");

        if (! $this->option('force') && ! $this->confirm('Proceed?')) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        User::whereIn('id', $matches->pluck('id'))->update(['email_unverifiable' => true]);

        $this->info("Updated {$matches->count()} user(s).");

        return self::SUCCESS;
    }
}
