<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DirectorRowOutcome;
use App\Enums\SchoolType;
use App\Enums\VersionInvitationStatus;
use App\Models\County;
use App\Models\Geostate;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\SchoolTeacherSubject;
use App\Models\Pronoun;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Support\DirectorUpload\DirectorRowPreview;
use App\Support\DirectorUpload\DirectorUploadResult;
use App\Support\DirectorUpload\DirectorUploadRow;
use App\Support\SchoolMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Event Manager director CSV upload (docs/plans/director-csv-upload.md):
 * matches each parsed row to existing User/Teacher/School/school_teacher
 * records, creating only what's missing, and invites the director to the
 * Version.
 *
 * preview() is read-only. commit() re-runs match() for every row rather
 * than trusting the preview — records may have been created between the
 * two requests, and an earlier row in the same file may have just created
 * the school/person a later row refers to — so duplicates are never
 * forced through by a stale preview.
 */
class DirectorUploadService
{
    /**
     * @param  list<DirectorUploadRow>  $rows
     * @return list<DirectorRowPreview>
     */
    public function preview(Version $version, array $rows): array
    {
        return array_map(fn (DirectorUploadRow $row): DirectorRowPreview => $this->match($version, $row), $rows);
    }

    /**
     * @param  list<DirectorUploadRow>  $rows
     * @param  array<int|string, array{school?: string, person?: string}>  $resolutions  keyed by CSV line number; values are an existing record id or 'new'
     */
    public function commit(Version $version, array $rows, array $resolutions, User $actor): DirectorUploadResult
    {
        $result = new DirectorUploadResult;

        foreach ($rows as $row) {
            $this->commitRow($version, $row, $resolutions[$row->line] ?? [], $actor, $result);
        }

        return $result;
    }

    public function match(Version $version, DirectorUploadRow $row): DirectorRowPreview
    {
        $preview = new DirectorRowPreview($row);

        if ($row->hasErrors()) {
            $preview->errors = $row->errors;

            return $this->finalize($preview);
        }

        $this->resolveLookups($preview);

        if ($preview->errors !== []) {
            return $this->finalize($preview);
        }

        $this->resolveSchool($version, $preview);
        $this->resolvePerson($preview);

        if ($preview->teacher !== null) {
            $preview->invitation = VersionInvitation::where('version_id', $version->id)
                ->where('teacher_id', $preview->teacher->id)
                ->first();

            if ($preview->invitation === null && $preview->school !== null) {
                $this->describeLink($preview->teacher, $preview->school, $preview);
            }
        }

        return $this->finalize($preview);
    }

    private function finalize(DirectorRowPreview $preview): DirectorRowPreview
    {
        $preview->outcome = match (true) {
            $preview->errors !== [] => DirectorRowOutcome::Error,
            $preview->invitation !== null => DirectorRowOutcome::AlreadyInvited,
            $preview->needsSchoolResolution() || $preview->needsPersonResolution() => DirectorRowOutcome::PossibleDuplicate,
            $preview->teacher !== null || $preview->user !== null => DirectorRowOutcome::Existing,
            default => DirectorRowOutcome::New,
        };

        if ($preview->outcome === DirectorRowOutcome::AlreadyInvited) {
            $status = VersionInvitationStatus::tryFrom((string) $preview->invitation?->getRawOriginal('status'));
            $preview->notes = ['Already on this Version\'s invitation list ('.($status?->label() ?? 'Invited').') — nothing will change.'];
        }

        return $preview;
    }

    private function resolveLookups(DirectorRowPreview $preview): void
    {
        $row = $preview->row;

        if ($row->pronoun !== null) {
            $preview->pronounId = $this->resolvePronounId($row->pronoun);

            if ($preview->pronounId === null) {
                $preview->errors[] = "Unknown pronoun \"{$row->pronoun}\" (use she, he, they, …).";
            }
        }

        $state = mb_strtolower($row->schoolState);
        $geostate = Geostate::query()
            ->where(fn ($q) => $q->whereRaw('LOWER(abbr) = ?', [$state])->orWhereRaw('LOWER(name) = ?', [$state]))
            ->first();

        if ($geostate === null) {
            $preview->errors[] = "Unknown school state \"{$row->schoolState}\".";

            return;
        }

        $preview->geostateId = $geostate->id;

        $countyName = mb_strtolower((string) $row->schoolCounty);
        $countyName = (string) preg_replace('/\s+county$/', '', $countyName);

        $county = County::where('geostate_id', $geostate->id)
            ->where(fn ($q) => $q->whereRaw('LOWER(name) = ?', [$countyName])->orWhereRaw('LOWER(name) = ?', [$countyName.' county']))
            ->first();

        if ($county === null) {
            $preview->errors[] = "Unknown county \"{$row->schoolCounty}\" in {$geostate->abbr}.";

            return;
        }

        $preview->countyId = $county->id;
    }

    private function resolvePronounId(string $pronoun): ?int
    {
        $needle = mb_strtolower(trim($pronoun));

        foreach (Pronoun::orderBy('sort_order')->get() as $candidate) {
            $description = mb_strtolower($candidate->description);

            if ($needle === mb_strtolower($candidate->personal)
                || $needle === $description
                || (str_contains($needle, '/') && str_starts_with($description, $needle))) {
                return $candidate->id;
            }
        }

        return null;
    }

    private function resolveSchool(Version $version, DirectorRowPreview $preview): void
    {
        $row = $preview->row;
        $countyIds = $version->counties()->pluck('county_id');
        $isEligible = fn (int $countyId): bool => $countyIds->isEmpty() || $countyIds->contains($countyId);

        $preview->school = School::with('county')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $row->schoolName)])
            ->where('zip_code', $row->schoolZip)
            ->first();

        if ($preview->school !== null) {
            if (! $isEligible($preview->school->county_id)) {
                $preview->errors[] = "{$preview->school->name} is in {$preview->school->county->name} County, which isn't one of this Version's counties.";
            } else {
                $preview->notes[] = "Matched existing school: {$preview->school->name}.";
            }

            return;
        }

        $preview->schoolCreateAllowed = $isEligible((int) $preview->countyId);

        $suggestions = SchoolMatcher::suggestions(
            (string) $row->schoolName,
            $preview->geostateId,
            $row->schoolZip,
            $preview->countyId,
            SchoolType::from($row->schoolType),
        )->filter(fn (array $match): bool => $isEligible($match['school']->county_id));

        if ($suggestions->isNotEmpty()) {
            $preview->schoolChoices = $suggestions
                ->map(function (array $match): array {
                    $school = $match['school'];
                    $school->loadMissing('county');

                    return [
                        'id' => $school->id,
                        'label' => "{$school->name} — {$school->city} {$school->zip_code}, {$school->county->name} County (".round($match['percent']).'% match)',
                    ];
                })
                ->values()
                ->all();

            return;
        }

        if (! $preview->schoolCreateAllowed) {
            $preview->errors[] = "{$row->schoolCounty} County isn't one of this Version's counties.";

            return;
        }

        $preview->notes[] = "New school will be created: {$row->schoolName} ({$row->schoolCity} {$row->schoolZip}).";
    }

    private function resolvePerson(DirectorRowPreview $preview): void
    {
        $row = $preview->row;

        $preview->user = User::with(['teacher', 'student'])->whereRaw('LOWER(email) = ?', [$row->email])->first();

        if ($preview->user !== null) {
            $preview->teacher = $preview->user->teacher;

            if ($preview->teacher === null && $preview->user->student !== null) {
                $preview->errors[] = "{$row->email} belongs to a student account.";

                return;
            }

            if (mb_strtolower($preview->user->first_name.' '.$preview->user->last_name) !== mb_strtolower($row->displayName())) {
                $preview->warnings[] = "Existing account is named {$preview->user->first_name} {$preview->user->last_name} — the CSV name won't be applied.";
            }

            $preview->notes[] = $preview->teacher !== null
                ? 'Matched existing teacher by email.'
                : 'Matched existing account by email — a teacher profile will be added.';

            return;
        }

        /** @var Collection<int, array{teacher: Teacher, reasons: list<string>}> $candidates */
        $candidates = collect();
        $addCandidate = function (Teacher $teacher, string $reason) use (&$candidates): void {
            $entry = $candidates->get($teacher->id, ['teacher' => $teacher, 'reasons' => []]);
            $entry['reasons'][] = $reason;
            $candidates->put($teacher->id, $entry);
        };

        if ($row->cellPhone !== null) {
            $phoneUser = User::with('teacher')->where('cell_phone', $row->cellPhone)->first();

            if ($phoneUser?->teacher !== null) {
                $addCandidate($phoneUser->teacher, 'same cell phone');
            } elseif ($phoneUser !== null) {
                $preview->warnings[] = 'That cell phone already belongs to another account — it won\'t be saved.';
            }
        }

        if ($row->schoolEmail !== null) {
            SchoolTeacher::with('teacher')
                ->whereRaw('LOWER(school_email) = ?', [$row->schoolEmail])
                ->get()
                ->each(fn (SchoolTeacher $link) => $addCandidate($link->teacher, 'same school email'));
        }

        $schoolIds = $preview->school !== null
            ? [$preview->school->id]
            : array_column($preview->schoolChoices, 'id');

        if ($schoolIds !== []) {
            Teacher::query()
                ->whereHas('schools', fn ($q) => $q->whereIn('schools.id', $schoolIds))
                ->whereHas('user', fn ($q) => $q
                    ->whereRaw('LOWER(first_name) = ?', [mb_strtolower((string) $row->firstName)])
                    ->whereRaw('LOWER(last_name) = ?', [mb_strtolower((string) $row->lastName)]))
                ->get()
                ->each(fn (Teacher $teacher) => $addCandidate($teacher, 'same name at this school'));
        }

        $preview->personChoices = $candidates
            ->map(function (array $entry): array {
                $teacher = $entry['teacher'];
                $teacher->loadMissing(['user', 'schools']);
                $schools = $teacher->schools->pluck('name')->implode(', ') ?: 'no school';

                return [
                    'id' => $teacher->id,
                    'label' => "{$teacher->user->name} — {$schools} — ".$this->maskEmail($teacher->user->email).' ('.implode(', ', $entry['reasons']).')',
                ];
            })
            ->values()
            ->all();

        if ($preview->personChoices === []) {
            $preview->notes[] = 'New director account will be created.';
        }
    }

    private function describeLink(Teacher $teacher, School $school, DirectorRowPreview $preview): void
    {
        $link = SchoolTeacher::where('school_id', $school->id)->where('teacher_id', $teacher->id)->first();

        if ($link === null) {
            $preview->notes[] = "{$school->name} will be added to this teacher's schools.";
        } elseif (! $link->is_active || $link->verified_at === null) {
            $preview->notes[] = "This teacher's link to {$school->name} will be set active and verified.";
        }
    }

    /**
     * @param  array{school?: string, person?: string}  $resolution
     */
    private function commitRow(Version $version, DirectorUploadRow $row, array $resolution, User $actor, DirectorUploadResult $result): void
    {
        $preview = $this->match($version, $row);

        if ($preview->outcome === DirectorRowOutcome::Error) {
            $result->add($row, 'skipped', implode(' ', $preview->errors));

            return;
        }

        if ($preview->outcome === DirectorRowOutcome::AlreadyInvited) {
            $result->add($row, 'already_invited', 'Already invited.');

            return;
        }

        $school = $preview->school;

        if ($preview->needsSchoolResolution()) {
            $choice = $resolution['school'] ?? '';

            if ($choice === 'new' && $preview->schoolCreateAllowed) {
                $school = null;
            } elseif (in_array((int) $choice, array_column($preview->schoolChoices, 'id'), true)) {
                $school = School::find((int) $choice);
            } else {
                $result->add($row, 'skipped', 'Needs review — possible duplicate school wasn\'t resolved (or matches changed since the preview). Re-upload to review it.');

                return;
            }
        }

        $user = $preview->user;
        $teacher = $preview->teacher;

        if ($preview->needsPersonResolution()) {
            $choice = $resolution['person'] ?? '';

            if ($choice === 'new') {
                $user = null;
                $teacher = null;
            } elseif (in_array((int) $choice, array_column($preview->personChoices, 'id'), true)) {
                $teacher = Teacher::with('user')->find((int) $choice);
                $user = $teacher?->user;
            } else {
                $result->add($row, 'skipped', 'Needs review — possible duplicate director wasn\'t resolved (or matches changed since the preview). Re-upload to review it.');

                return;
            }

            if ($teacher !== null && VersionInvitation::where('version_id', $version->id)->where('teacher_id', $teacher->id)->exists()) {
                $result->add($row, 'already_invited', 'Already invited.');

                return;
            }
        }

        DB::transaction(function () use ($version, $row, $preview, $school, $user, $teacher, $actor, $result): void {
            if ($school === null) {
                $school = School::create([
                    'name' => $row->schoolName,
                    'type' => $row->schoolType,
                    'city' => $row->schoolCity,
                    'zip_code' => $row->schoolZip,
                    'geostate_id' => $preview->geostateId,
                    'county_id' => $preview->countyId,
                    'school_year' => 'US',
                ]);
                $result->newSchools++;
            }

            if ($user === null) {
                $phoneTaken = $row->cellPhone !== null && User::where('cell_phone', $row->cellPhone)->exists();

                $user = User::create([
                    'honorific' => $row->honorific,
                    'first_name' => $row->firstName,
                    'middle_name' => $row->middleName,
                    'last_name' => $row->lastName,
                    'suffix_name' => $row->suffixName,
                    'pronoun_id' => $preview->pronounId,
                    'email' => $row->email,
                    'cell_phone' => $phoneTaken ? null : $row->cellPhone,
                    'password' => null,
                ]);
                $result->newUsers++;
            }

            if ($teacher === null) {
                // Step 3 (Students) when the upload already captured
                // school + role + subjects, so the onboarding wizard doesn't
                // re-ask for a school and create a second link; step 2
                // (Role & Subjects) when no subjects were given.
                $teacher = Teacher::create([
                    'user_id' => $user->id,
                    'onboarding_step' => $row->subjects === [] ? 2 : 3,
                ]);
            }

            if (! $user->hasRole('Teacher')) {
                $user->assignRole('Teacher');
            }

            $link = SchoolTeacher::where('school_id', $school->id)->where('teacher_id', $teacher->id)->first();

            if ($link === null) {
                $link = SchoolTeacher::create([
                    'school_id' => $school->id,
                    'teacher_id' => $teacher->id,
                    'role' => $row->role,
                    'is_active' => true,
                    'school_email' => $row->schoolEmail,
                    'verified_at' => now(),
                ]);
                $result->newSchoolLinks++;
            } else {
                $changes = [];

                if (! $link->is_active) {
                    $changes['is_active'] = true;
                }

                if ($link->verified_at === null) {
                    $changes['verified_at'] = now();
                }

                if (blank($link->school_email) && $row->schoolEmail !== null) {
                    $changes['school_email'] = $row->schoolEmail;
                }

                if ($changes !== []) {
                    $link->update($changes);
                }
            }

            foreach ($row->subjects as $subject) {
                SchoolTeacherSubject::firstOrCreate(['school_teacher_id' => $link->id, 'subject' => $subject]);
            }

            VersionInvitation::create([
                'version_id' => $version->id,
                'teacher_id' => $teacher->id,
                'status' => VersionInvitationStatus::Invited->value,
                'invited_at' => now(),
                'invited_by_user_id' => $actor->id,
            ]);

            $result->add($row, 'invited', "Invited ({$school->name}).");
        });
    }

    private function maskEmail(?string $email): string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return 'no email';
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
