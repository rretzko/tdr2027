<?php

declare(strict_types=1);

namespace App\Support\RegistrationHistory;

use Illuminate\Support\Collection;

/**
 * A student's or teacher's registration history for one Version: the header
 * facts plus a timeline, oldest first. Screen and .txt both render this.
 */
final readonly class RegistrationHistory
{
    /**
     * @param  array<string, string>  $facts  header label => value
     * @param  list<array{label: string, done: bool}>  $checklist  student histories only
     * @param  list<array{student: string, status: string, missing: list<string>}>  $incomplete  teacher histories only
     * @param  Collection<int, HistoryEntry>  $entries
     */
    public function __construct(
        public string $kind,
        public string $name,
        public string $versionName,
        public int $versionId,
        public array $facts,
        public array $checklist,
        public array $incomplete,
        public Collection $entries,
        public string $fileKey,
    ) {}

    public function isTeacher(): bool
    {
        return $this->kind === 'Teacher';
    }
}
