<?php

declare(strict_types=1);

namespace App\Support\DirectorUpload;

/**
 * Outcome of DirectorUploadService::commit(): headline counts plus one
 * line per CSV row saying what actually happened to it.
 */
final class DirectorUploadResult
{
    public int $invited = 0;

    public int $alreadyInvited = 0;

    public int $skipped = 0;

    public int $newUsers = 0;

    public int $newSchools = 0;

    public int $newSchoolLinks = 0;

    /** @var list<array{line: int, name: string, status: string, message: string}> */
    public array $rows = [];

    public function add(DirectorUploadRow $row, string $status, string $message): void
    {
        match ($status) {
            'invited' => $this->invited++,
            'already_invited' => $this->alreadyInvited++,
            default => $this->skipped++,
        };

        $this->rows[] = ['line' => $row->line, 'name' => $row->displayName(), 'status' => $status, 'message' => $message];
    }

    public function changedAnything(): bool
    {
        return $this->invited > 0;
    }

    /**
     * @return array{invited: int, alreadyInvited: int, skipped: int, newUsers: int, newSchools: int, newSchoolLinks: int, rows: list<array{line: int, name: string, status: string, message: string}>}
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
