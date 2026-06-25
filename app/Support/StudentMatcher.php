<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\EmergencyContact;
use App\Models\Student;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class StudentMatcher
{
    private const MIN_PERCENT = 70.0;

    /**
     * Score floor for a name that's an exact match once case/whitespace are
     * normalized — mirrors SchoolMatcher's substring-match floor, since
     * similar_text() otherwise penalizes short names too heavily.
     */
    private const EXACT_NAME_PERCENT = 90.0;

    private const MAX_SUGGESTIONS = 5;

    private const MAX_CANDIDATES = 500;

    /**
     * Finds existing students who might be the same person as the one being
     * entered, so a teacher is steered toward attaching to an existing record
     * instead of creating a duplicate identity.
     *
     * "Strong" matches — an exact birthday plus a close name match, or an exact
     * emergency-contact email/phone match — are treated as effectively certain.
     * "Weak" matches (name similarity alone, since birthday isn't required when
     * adding a student) are just a heads-up, not proof.
     *
     * @return Collection<int, array{student: Student, tier: string}>
     */
    public static function suggestions(
        string $firstName,
        string $lastName,
        ?string $birthday,
        ?string $emergencyEmail,
        ?string $emergencyCellPhone,
    ): Collection {
        $firstName = trim($firstName);
        $lastName = trim($lastName);

        if ($firstName === '' || $lastName === '') {
            return collect();
        }

        $matches = Student::query()
            ->with('user')
            ->whereHas('user', fn ($query) => $query->where('last_name', 'like', '%'.$lastName.'%'))
            ->limit(self::MAX_CANDIDATES)
            ->get()
            ->map(fn (Student $student) => [
                'student' => $student,
                'percent' => self::nameScore($firstName, $lastName, $student),
            ])
            ->filter(fn (array $match) => $match['percent'] >= self::MIN_PERCENT)
            ->map(fn (array $match) => [
                'student' => $match['student'],
                'tier' => self::tierFor($match['student'], $birthday, $match['percent']),
                'percent' => $match['percent'],
            ])
            ->keyBy(fn (array $match) => $match['student']->id);

        foreach (self::contactMatches($emergencyEmail, $emergencyCellPhone) as $student) {
            $matches->put($student->id, ['student' => $student, 'tier' => 'strong', 'percent' => 100.0]);
        }

        return $matches
            ->sortByDesc(fn (array $match) => ($match['tier'] === 'strong' ? 1000 : 0) + $match['percent'])
            ->take(self::MAX_SUGGESTIONS)
            ->map(fn (array $match) => ['student' => $match['student'], 'tier' => $match['tier']])
            ->values();
    }

    private static function nameScore(string $firstName, string $lastName, Student $student): float
    {
        $user = $student->user;
        $needle = mb_strtolower($firstName.' '.$lastName);
        $haystack = mb_strtolower(($user->first_name ?? '').' '.($user->last_name ?? ''));

        similar_text($needle, $haystack, $percent);

        if ($needle === $haystack) {
            return max($percent, self::EXACT_NAME_PERCENT);
        }

        return $percent;
    }

    private static function tierFor(Student $student, ?string $birthday, float $namePercent): string
    {
        $rawBirthday = $student->getRawOriginal('birthday');

        $sameBirthday = $birthday !== null
            && $birthday !== ''
            && $rawBirthday !== null
            && Carbon::parse($rawBirthday)->isSameDay(Carbon::parse($birthday));

        return $sameBirthday && $namePercent >= self::EXACT_NAME_PERCENT ? 'strong' : 'weak';
    }

    /**
     * Exact matches on an emergency contact's email or cell phone — a family
     * identifier that's stronger than a name match, since it doesn't suffer
     * from nicknames/spelling variants the way names do. Phone is compared
     * digits-only since that's how it's stored (see PhoneNormalizer).
     *
     * @return Collection<int, Student>
     */
    private static function contactMatches(?string $email, ?string $cellPhone): Collection
    {
        $email = trim((string) $email);
        $cellPhone = PhoneNormalizer::normalize($cellPhone) ?? '';

        if ($email === '' && $cellPhone === '') {
            return collect();
        }

        return EmergencyContact::query()
            ->where(function ($query) use ($email, $cellPhone) {
                if ($email !== '') {
                    $query->orWhere('email', $email);
                }

                if ($cellPhone !== '') {
                    $query->orWhere('cell_phone', $cellPhone);
                }
            })
            ->with('student')
            ->get()
            ->map(fn (EmergencyContact $contact) => $contact->student)
            ->filter()
            ->unique(fn (Student $student) => $student->id)
            ->values();
    }
}
