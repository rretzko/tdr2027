<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\CandidateUploadFile;
use App\Models\Version;
use App\Models\VoicePart;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Report (and optionally repair) a Version's Candidates whose voice part is
 * not offered to their grade — e.g. a 10th grader on the Middle School
 * Chorus "Soprano" when the High School Chorus only offers Soprano I/II.
 * The Candidate edit modal can't show such a value (it only lists the
 * grade's voice parts), so the browser falls back to the first option and a
 * plain Save silently changes the voice part.
 *
 * A Candidate gets a proposed replacement only when exactly one rule
 * applies: the first of the grade's voice parts (in display order) whose
 * name extends the current one ("Soprano" → "Soprano I", "Alto" → "Alto I").
 * Everything else (Descant, High/Low Baritone, …) is listed for manual
 * review. --apply only writes proposals for Candidates still in
 * registration (Eligible/Pending/Registered) — later states are tied to
 * adjudication results and are left alone.
 *
 * The list carries each Candidate's uploaded recordings so a reviewer can
 * confirm the voice part against what the student actually sang.
 */
class RepairCandidateVoicePartGrade extends Command
{
    protected $signature = 'candidates:voice-part-mismatches
        {version : The Version id}
        {--csv : Print the full list as CSV (for review) instead of tables}
        {--apply : Write the proposed voice parts (default is a dry run)}
        {--force : Skip the confirmation prompt when applying}';

    protected $description = 'List (and optionally repair) Candidates whose voice part is not offered to their grade (dry run by default)';

    private const array CSV_HEADERS = [
        'candidate_id', 'student_last', 'student_first', 'grade', 'teacher_last', 'teacher_first', 'school',
        'status', 'current_voice_part', 'proposed_voice_part', 'action', 'grade_voice_parts', 'upload_count', 'uploads',
    ];

    public function handle(): int
    {
        $version = Version::withoutGlobalScopes()->find((int) $this->argument('version'));

        if ($version === null) {
            $this->error("Version {$this->argument('version')} not found.");

            return self::FAILURE;
        }

        $rows = $this->mismatches($version);

        if ($this->option('csv')) {
            $this->writeCsv($rows);
        } else {
            $this->writeTables($version, $rows);
        }

        $fixable = $rows->where('action', 'apply');

        if (! $this->option('apply')) {
            if (! $this->option('csv')) {
                $this->info("Dry run — {$fixable->count()} Candidate(s) would be changed. Re-run with --apply to write.");
            }

            return self::SUCCESS;
        }

        if ($fixable->isEmpty()) {
            $this->info('Nothing to apply.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Change the voice part of {$fixable->count()} Candidate(s)?")) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($fixable): void {
            foreach ($fixable as $row) {
                Candidate::withoutGlobalScopes()
                    ->whereKey($row['candidate_id'])
                    ->update(['voice_part_id' => $row['proposed_voice_part_id'], 'updated_at' => now()]);
            }
        });

        $this->info("Changed {$fixable->count()} Candidate(s).");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function mismatches(Version $version): Collection
    {
        $candidates = Candidate::withoutGlobalScopes()
            ->where('version_id', $version->id)
            ->with([
                'student.user', 'student.schools', 'teacher.user', 'school', 'voicePart',
                'uploadFiles.versionUploadFile',
            ])
            ->get();

        /** @var array<string, Collection<int, VoicePart>> $byGrade */
        $byGrade = [];
        $rows = collect();

        foreach ($candidates as $candidate) {
            $grade = $candidate->student->grade;
            $gradeKey = (string) $grade;
            $byGrade[$gradeKey] ??= $version->availableVoicePartsForGrade($grade);
            $allowed = $byGrade[$gradeKey];

            if ($allowed->contains('id', $candidate->voice_part_id)) {
                continue;
            }

            $current = $candidate->voicePart;
            $proposed = $current !== null
                ? $allowed->first(fn (VoicePart $voicePart): bool => str_starts_with($voicePart->name, $current->name.' '))
                : null;
            $status = CandidateStatus::from((string) $candidate->getRawOriginal('status'));

            $action = match (true) {
                $proposed === null => 'review: no automatic match',
                ! $status->isActive() => 'review: status '.$status->label(),
                default => 'apply',
            };

            $rows->push([
                'candidate_id' => $candidate->id,
                'student_last' => $candidate->student->user->last_name,
                'student_first' => $candidate->student->user->first_name,
                'grade' => $grade ?? '',
                'teacher_last' => $candidate->teacher->user->last_name ?? '',
                'teacher_first' => $candidate->teacher->user->first_name ?? '',
                'school' => $candidate->school->name ?? '',
                'status' => $status->label(),
                'current_voice_part' => $current->name ?? '(none)',
                'proposed_voice_part' => $proposed->name ?? '',
                'proposed_voice_part_id' => $proposed?->id,
                'action' => $action,
                'grade_voice_parts' => $allowed->pluck('name')->implode(', '),
                'upload_count' => $candidate->uploadFiles->count(),
                'uploads' => $candidate->uploadFiles
                    ->sortBy(fn (CandidateUploadFile $file): int => $file->versionUploadFile->order_by ?? 0)
                    ->map(fn (CandidateUploadFile $file): string => sprintf(
                        '%s [%s] %s',
                        $file->versionUploadFile->name ?? 'Upload',
                        (string) $file->getRawOriginal('status'),
                        (string) $file->original_filename,
                    ))
                    ->implode(' | '),
            ]);
        }

        return $rows
            ->sortBy(fn (array $row): string => strtolower("{$row['teacher_last']} {$row['teacher_first']} {$row['student_last']} {$row['student_first']}"))
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function writeCsv(Collection $rows): void
    {
        $this->output->writeln($this->csvLine(self::CSV_HEADERS), OutputInterface::OUTPUT_RAW);

        foreach ($rows as $row) {
            $this->output->writeln(
                $this->csvLine(array_map(fn (string $key): mixed => $row[$key], self::CSV_HEADERS)),
                OutputInterface::OUTPUT_RAW,
            );
        }
    }

    /**
     * @param  array<int, mixed>  $fields
     */
    private function csvLine(array $fields): string
    {
        $handle = fopen('php://memory', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, $fields, escape: '');
        rewind($handle);
        $line = rtrim((string) stream_get_contents($handle), "\n");
        fclose($handle);

        return $line;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function writeTables(Version $version, Collection $rows): void
    {
        if ($rows->isEmpty()) {
            $this->info("Every Candidate in {$version->name} has a voice part offered to their grade.");

            return;
        }

        $headers = ['Candidate', 'Student', 'Gr', 'Teacher', 'Status', 'Current', 'Proposed', 'Uploads'];
        $toRow = fn (array $row): array => [
            $row['candidate_id'],
            "{$row['student_last']}, {$row['student_first']}",
            $row['grade'],
            "{$row['teacher_last']}, {$row['teacher_first']}",
            $row['status'],
            $row['current_voice_part'],
            $row['proposed_voice_part'] !== '' ? $row['proposed_voice_part'] : '—',
            $row['upload_count'],
        ];

        [$fixable, $review] = $rows->partition(fn (array $row): bool => $row['action'] === 'apply');

        if ($fixable->isNotEmpty()) {
            $this->info("Would change ({$fixable->count()}):");
            $this->table($headers, $fixable->map($toRow)->all());
        }

        if ($review->isNotEmpty()) {
            $this->warn("Needs manual review ({$review->count()}):");
            $this->table([...$headers, 'Reason'], $review->map(fn (array $row): array => [...$toRow($row), $row['action']])->all());
        }

        $this->line('Use --csv for the full list with schools, grade options and upload details.');
    }
}
