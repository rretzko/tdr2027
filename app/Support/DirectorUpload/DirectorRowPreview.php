<?php

declare(strict_types=1);

namespace App\Support\DirectorUpload;

use App\Enums\DirectorRowOutcome;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\VersionInvitation;

/**
 * The result of matching one DirectorUploadRow against the database
 * (DirectorUploadService::match()). Holds the resolved models commit()
 * needs, plus the display messages the preview step shows.
 *
 * $schoolChoices/$personChoices are only populated for a
 * PossibleDuplicate: each is a list of existing records the Event Manager
 * can pick instead of creating a new one.
 */
final class DirectorRowPreview
{
    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $notes = [];

    public ?int $pronounId = null;

    public ?int $geostateId = null;

    public ?int $countyId = null;

    public ?School $school = null;

    /** @var list<array{id: int, label: string}> */
    public array $schoolChoices = [];

    /** Whether "Create new school" is a valid resolution (the CSV county is eligible for this Version). */
    public bool $schoolCreateAllowed = true;

    public ?User $user = null;

    public ?Teacher $teacher = null;

    /** @var list<array{id: int, label: string}> */
    public array $personChoices = [];

    public ?VersionInvitation $invitation = null;

    public DirectorRowOutcome $outcome = DirectorRowOutcome::New;

    public function __construct(public readonly DirectorUploadRow $row) {}

    public function needsSchoolResolution(): bool
    {
        return $this->school === null && $this->schoolChoices !== [];
    }

    public function needsPersonResolution(): bool
    {
        return $this->personChoices !== [];
    }

    /**
     * @return array{line: int, name: string, email: ?string, school: ?string, outcome: string, errors: list<string>, warnings: list<string>, notes: list<string>, schoolChoices: list<array{id: int, label: string}>, schoolCreateAllowed: bool, personChoices: list<array{id: int, label: string}>}
     */
    public function toArray(): array
    {
        return [
            'line' => $this->row->line,
            'name' => $this->row->displayName(),
            'email' => $this->row->email,
            'school' => $this->row->schoolName,
            'outcome' => $this->outcome->value,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'notes' => $this->notes,
            'schoolChoices' => $this->needsSchoolResolution() ? $this->schoolChoices : [],
            'schoolCreateAllowed' => $this->schoolCreateAllowed,
            'personChoices' => $this->personChoices,
        ];
    }
}
