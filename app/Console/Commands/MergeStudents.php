<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Student;
use App\Services\StudentMergeService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class MergeStudents extends Command
{
    protected $signature = 'students:merge
        {winner : Student ID to keep}
        {loser  : Student ID to delete}
        {--force : Skip confirmation prompt}';

    protected $description = 'Merge a duplicate student (loser) into another (winner) and permanently delete the loser';

    public function handle(StudentMergeService $merger): int
    {
        $winner = Student::with('user')->find($this->argument('winner'));
        $loser = Student::with('user')->find($this->argument('loser'));

        if ($winner === null || $loser === null) {
            $this->error('One or both Student IDs not found.');

            return self::FAILURE;
        }

        if ($winner->id === $loser->id) {
            $this->error('Winner and loser must be different students.');

            return self::FAILURE;
        }

        $this->displaySummary($winner, $loser);

        if (! $this->option('force') && ! $this->confirm("Merge loser #{$loser->id} into winner #{$winner->id} and permanently delete the loser?")) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        try {
            $log = $merger->merge($winner, $loser);
        } catch (\DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Merge complete.');
        $this->table(['Step', 'Result'], $log);

        return self::SUCCESS;
    }

    private function displaySummary(Student $winner, Student $loser): void
    {
        $this->table(
            ['Role', 'Student ID', 'Name', 'Email', 'Birthday'],
            [
                [
                    'KEEP (winner)',
                    $winner->id,
                    $winner->user->first_name.' '.$winner->user->last_name,
                    $winner->user->email,
                    $winner->birthday !== null ? Carbon::parse($winner->birthday)->format('Y-m-d') : '—',
                ],
                [
                    'DELETE (loser)',
                    $loser->id,
                    $loser->user->first_name.' '.$loser->user->last_name,
                    $loser->user->email,
                    $loser->birthday !== null ? Carbon::parse($loser->birthday)->format('Y-m-d') : '—',
                ],
            ]
        );
    }
}
