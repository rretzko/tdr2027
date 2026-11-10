<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Pivots\SchoolTeacher;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Support\CommercialEmailDomains;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Self-registered SFDI students who never joined a school, matched to a
 * teacher's verified schools by email domain. About 1 in 4 self-registrations
 * stall this way, mostly at email verification — district mail filters often
 * swallow the verification email — so nothing sent to the student can fix it.
 * The teacher can: this surfaces them on the Students page to add directly.
 *
 * A school's domains are its verified teacher's school_email domain (plus any
 * subdomain of it, e.g. students.district.org) and the domains its enrolled
 * students already use. Free webmail domains never count — a gmail address
 * says nothing about which school someone attends.
 *
 * users.email_unverifiable is deliberately NOT used to tell self-registered
 * from teacher-created students: it means "skip email verification" and is
 * set on self-registered school addresses too (EmailVerifiabilityChecker).
 * Teacher-created students are excluded by having a roster row instead, and
 * their placeholder studentfolder.info domain never matches a school.
 */
class UnjoinedSignupFinder
{
    /** Older sign-ups have almost certainly moved on (or graduated). */
    private const LOOKBACK_DAYS = 180;

    private const MAX_RESULTS = 25;

    private const MIN_STUDENTS_PER_DOMAIN = 3;

    private const PLACEHOLDER_DOMAIN = 'studentfolder.info';

    /**
     * @return Collection<int, array{student: Student, school: School, domain: string}>
     */
    public function forTeacher(Teacher $teacher): Collection
    {
        $schoolDomains = $this->schoolDomains($teacher);

        if ($schoolDomains === []) {
            return collect();
        }

        $dismissedIds = DB::table('student_signup_dismissals')
            ->where('teacher_id', $teacher->id)
            ->pluck('student_id');

        $schools = School::whereIn('id', array_keys($schoolDomains))->get()->keyBy('id');

        $candidates = Student::query()
            ->with('user')
            ->join('users', 'users.id', '=', 'students.user_id')
            ->select('students.*')
            ->where('users.created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->whereNotIn('students.id', $dismissedIds)
            ->whereNotExists(fn ($q) => $q->from('teachers')->whereColumn('teachers.user_id', 'users.id'))
            ->whereNotExists(fn ($q) => $q->from('student_teacher')->whereColumn('student_teacher.student_id', 'students.id'))
            ->whereNotExists(fn ($q) => $q->from('school_student')
                ->whereColumn('school_student.student_id', 'students.id')
                ->where('school_student.is_active', true))
            ->orderByDesc('users.created_at')
            ->get();

        /** @var Collection<int, array{student: Student, school: School, domain: string}> $matches */
        $matches = collect();

        foreach ($candidates as $student) {
            $domain = self::domainOf((string) $student->user->email);
            $school = $schools->get($this->matchingSchoolId($domain, $schoolDomains));

            if ($school !== null) {
                $matches->push(['student' => $student, 'school' => $school, 'domain' => '@'.$domain]);
            }

            if ($matches->count() >= self::MAX_RESULTS) {
                break;
            }
        }

        return $matches;
    }

    /**
     * Whether a student appears in forTeacher() — the guard for acting on one,
     * so a stale or crafted id can't attach an arbitrary student.
     *
     * @return array{student: Student, school: School, domain: string}|null
     */
    public function matchFor(Teacher $teacher, int $studentId): ?array
    {
        return $this->forTeacher($teacher)->first(fn (array $match) => $match['student']->id === $studentId);
    }

    /**
     * school_id => domains, for the teacher's active, verified schools (the
     * same gate as student access — see school_teacher.verified_at). Domains
     * are kept as list values, never array keys: PHP would turn a numeric-
     * looking key into an int.
     *
     * @return array<int, list<string>>
     */
    private function schoolDomains(Teacher $teacher): array
    {
        $links = SchoolTeacher::query()
            ->where('teacher_id', $teacher->id)
            ->where('is_active', true)
            ->whereNotNull('verified_at')
            ->get(['school_id', 'school_email']);

        $result = [];

        foreach ($links as $link) {
            $domains = $this->widelyUsedStudentDomains((int) $link->school_id);
            $domains[] = self::domainOf((string) $link->school_email);

            $domains = array_values(array_unique(array_filter($domains, fn (string $domain) => self::isSchoolDomain($domain))));

            if ($domains !== []) {
                $result[(int) $link->school_id] = $domains;
            }
        }

        return $result;
    }

    /**
     * Domains used by at least MIN_STUDENTS_PER_DOMAIN enrolled students. A
     * domain only a family or two use (comcast.net, optonline.net — ISPs
     * aren't on the commercial list) would match strangers statewide; a
     * district's student domain is shared widely.
     *
     * @return list<string>
     */
    private function widelyUsedStudentDomains(int $schoolId): array
    {
        /** @var array<string, int> $counts */
        $counts = [];

        $emails = DB::table('school_student')
            ->join('students', 'students.id', '=', 'school_student.student_id')
            ->join('users', 'users.id', '=', 'students.user_id')
            ->where('school_student.school_id', $schoolId)
            ->where('school_student.is_active', true)
            ->pluck('users.email');

        foreach ($emails as $email) {
            $domain = self::domainOf((string) $email);

            if ($domain !== '') {
                $counts['d:'.$domain] = ($counts['d:'.$domain] ?? 0) + 1;
            }
        }

        $domains = [];

        foreach ($counts as $key => $count) {
            if ($count >= self::MIN_STUDENTS_PER_DOMAIN) {
                $domains[] = substr($key, 2);
            }
        }

        return $domains;
    }

    /**
     * @param  array<int, list<string>>  $schoolDomains
     */
    private function matchingSchoolId(string $domain, array $schoolDomains): ?int
    {
        if (! self::isSchoolDomain($domain)) {
            return null;
        }

        foreach ($schoolDomains as $schoolId => $domains) {
            foreach ($domains as $schoolDomain) {
                if ($domain === $schoolDomain || str_ends_with($domain, '.'.$schoolDomain)) {
                    return $schoolId;
                }
            }
        }

        return null;
    }

    /**
     * Free webmail says nothing about which school someone attends, and
     * studentfolder.info is the placeholder domain for teacher-created and
     * email-less accounts — both are shared across every school.
     */
    private static function isSchoolDomain(string $domain): bool
    {
        return $domain !== ''
            && $domain !== self::PLACEHOLDER_DOMAIN
            && ! str_ends_with($domain, '.'.self::PLACEHOLDER_DOMAIN)
            && ! CommercialEmailDomains::matches('x@'.$domain);
    }

    private static function domainOf(string $email): string
    {
        return str_contains($email, '@') ? mb_strtolower(trim(Str::afterLast($email, '@'))) : '';
    }
}
