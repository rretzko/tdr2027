<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CandidateStatus;
use App\Enums\EventStatus;
use App\Enums\Subject;
use App\Enums\TeacherRole;
use App\Enums\VersionDateType;
use App\Models\AuditionResult;
use App\Models\Candidate;
use App\Models\CandidateStatusHistory;
use App\Models\County;
use App\Models\Ensemble;
use App\Models\EnsembleGrade;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Pivots\SchoolStudent;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\StudentTeacher;
use App\Models\Recording;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionFee;
use App\Models\VoicePart;
use App\Services\VersionRoleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Builds a complete, fictional "Sample Honor Choir Association" dataset on
 * top of the base reference seeders (Geostate/County/Pronoun/VoicePart/
 * Instrument/Roles) for demoing the live product on the staging
 * environment. Entirely synthetic — deliberately NOT wired into
 * DatabaseSeeder::run(), so a normal `php artisan db:seed` never touches
 * it. Run standalone:
 *
 *   php artisan db:seed --class=SampleHonorChoirAssociationSeeder
 *
 * Idempotent: re-running wipes any previously seeded sample data (matched
 * by the fixed organization/school names below) before rebuilding it, so
 * it's safe to run again before a specific prospect call.
 *
 * Scope note: this seeder does NOT populate the judge/rubric/room
 * adjudication pipeline (score_categories, score_factors, rooms,
 * room_judges, scores) — that rubric is configured per-Version through the
 * VersionScoringRubric admin screen, not seed data, and reproducing it here
 * risked violating invariants for little demo value. Instead, "scored"
 * candidates in the closed cycle get a terminal status (Accepted /
 * NotAccepted), a frozen AuditionResult tally row, and — for Accepted
 * candidates — an accepted_ensemble_id, which is what the Registration
 * Manager / Tab Room / results screens actually read from.
 */
class SampleHonorChoirAssociationSeeder extends Seeder
{
    private const ORG_NAME = 'Sample County Honor Choir Association';

    /** @var list<string> */
    private const SCHOOL_NAMES = [
        'Sample North High School',
        'Sample Valley High School',
        'Sample Ridge Middle School',
    ];

    private const CLOSED_VERSION_NAME = 'Fall 2025 Sample Cycle';

    private const ACTIVE_VERSION_NAME = 'Fall 2026 Sample Cycle';

    private const DEMO_PASSWORD = 'password';

    public function run(VersionRoleService $versionRoles): void
    {
        $this->command->info('Removing any previously seeded sample data...');
        $this->wipeExisting();

        DB::transaction(function () use ($versionRoles): void {
            // CandidateObserver writes candidate_status_history.user_id from
            // Auth::id(), and that column is NOT NULL — a console seeder run
            // has no authenticated user by default, so every Candidate::
            // create()/update() below would fail without this. Logging in as
            // the demo Event Manager also makes the resulting history trail
            // narratively correct ("created by staff").
            $eventManager = User::factory()->create([
                'first_name' => 'Demo',
                'last_name' => 'EventManager',
                'email' => 'demo.eventmanager@sample-honorchoir.example',
                'password' => Hash::make(self::DEMO_PASSWORD),
            ]);
            Auth::login($eventManager);

            $organization = Organization::create([
                'name' => self::ORG_NAME,
                'parent_id' => null,
                'logo_file_url' => null,
                'logo_file_alt' => null,
            ]);

            $event = Event::factory()->create([
                'organization_id' => $organization->id,
                'name' => 'Sample All-State Honor Choir',
                'short_name' => 'Sample Honor Choir',
                'status' => EventStatus::Active,
                'audition_count' => 1,
                'ensemble_count' => 3,
            ]);

            $ensembles = $this->buildEnsembles($event);
            $schools = $this->buildSchools();
            $teachers = $this->buildTeachers($schools);

            $closedVersion = Version::factory()->create([
                'event_id' => $event->id,
                'name' => self::CLOSED_VERSION_NAME,
                'short_name' => 'Fall 2025',
                'senior_class_of' => 2026,
                'status' => EventStatus::Closed,
                'results_released_at' => now()->subMonths(4),
            ]);
            $this->buildVersionConfig($closedVersion, feeCents: 2000, registrationOpensAgo: '-8 months', registrationClosesAgo: '-6 months');

            $activeVersion = Version::factory()->create([
                'event_id' => $event->id,
                'name' => self::ACTIVE_VERSION_NAME,
                'short_name' => 'Fall 2026',
                'senior_class_of' => 2027,
                'status' => EventStatus::Active,
            ]);
            $this->buildVersionConfig($activeVersion, feeCents: 2500, registrationOpensAgo: '-2 weeks', registrationClosesAgo: null);

            $studentsBySchool = $this->buildStudentsAndEnroll($schools, $teachers);

            $this->buildClosedCycleCandidates($closedVersion, $schools, $studentsBySchool, $teachers, $ensembles);
            $this->buildActiveCycleCandidates($activeVersion, $schools, $studentsBySchool, $teachers);

            $this->buildDemoLogins($versionRoles, $eventManager, $teachers, $closedVersion, $activeVersion);

            Auth::logout();
        });

        $this->command->info('Sample Honor Choir Association demo data seeded.');
        $this->command->info('Demo logins (password: "'.self::DEMO_PASSWORD.'"): see docs/plans/staging-demo-setup.md');
    }

    /**
     * @return array<int, Ensemble>
     */
    private function buildEnsembles(Event $event): array
    {
        $definitions = [
            ['name' => 'Treble Honor Choir', 'abbreviation' => 'TRB'],
            ['name' => 'Mixed Honor Choir', 'abbreviation' => 'MIX'],
            ['name' => 'Bass-Baritone Honor Choir', 'abbreviation' => 'BB'],
        ];

        return collect($definitions)->map(function (array $def) use ($event) {
            $ensemble = Ensemble::factory()->create([
                'event_id' => $event->id,
                'name' => $def['name'],
                'short_name' => null,
                'abbreviation' => $def['abbreviation'],
            ]);

            foreach ([9, 10, 11, 12] as $grade) {
                EnsembleGrade::create(['ensemble_id' => $ensemble->id, 'grade' => $grade]);
            }

            return $ensemble;
        })->all();
    }

    /**
     * @return array<int, School>
     */
    private function buildSchools(): array
    {
        $county = County::query()->inRandomOrder()->first() ?? County::factory()->create();

        return collect(self::SCHOOL_NAMES)->map(fn (string $name) => School::factory()->create([
            'name' => $name,
            'city' => 'Sampleton',
            'county_id' => $county->id,
        ]))->all();
    }

    /**
     * @param  array<int, School>  $schools
     * @return array<int, Teacher> keyed by school index (0, 1, 2), one primary teacher per school
     */
    private function buildTeachers(array $schools): array
    {
        $names = [
            ['Dana', 'Whitfield'],
            ['Marcus', 'Ibarra'],
            ['Priya', 'Chandran'],
        ];

        $teachers = [];

        foreach ($schools as $i => $school) {
            [$first, $last] = $names[$i];

            $user = User::factory()->create([
                'first_name' => $first,
                'last_name' => $last,
                'email' => strtolower($first.'.'.$last).'@sample-honorchoir.example',
                'password' => Hash::make(self::DEMO_PASSWORD),
            ]);
            $user->assignRole('Teacher');

            $teacher = Teacher::factory()->create([
                'user_id' => $user->id,
                'onboarding_step' => 1,
                'onboarding_completed_at' => now()->subYear(),
            ]);

            SchoolTeacher::factory()->create([
                'school_id' => $school->id,
                'teacher_id' => $teacher->id,
                'role' => TeacherRole::Primary,
                'is_active' => true,
                'school_email' => $user->email,
                'verified_at' => now()->subYear(),
            ]);

            $teachers[$i] = $teacher;
        }

        return $teachers;
    }

    private function buildVersionConfig(Version $version, int $feeCents, string $registrationOpensAgo, ?string $registrationClosesAgo): void
    {
        VersionFee::create([
            'version_id' => $version->id,
            'registration' => $feeCents,
            'on_site_registration' => 0,
            'participation' => 0,
            'epayment_surcharge' => 0,
            'housing' => 0,
        ]);

        VersionDate::create([
            'version_id' => $version->id,
            'date_type' => VersionDateType::Candidate,
            'start_at' => now()->modify($registrationOpensAgo),
            'end_at' => $registrationClosesAgo !== null ? now()->modify($registrationClosesAgo) : now()->addMonth(),
        ]);

        VersionDate::create([
            'version_id' => $version->id,
            'date_type' => VersionDateType::Teacher,
            'start_at' => now()->modify($registrationOpensAgo),
            'end_at' => null,
        ]);
    }

    /**
     * @param  array<int, School>  $schools
     * @param  array<int, Teacher>  $teachers
     * @return array<int, array<int, Student>> students keyed by school index
     */
    private function buildStudentsAndEnroll(array $schools, array $teachers): array
    {
        $studentsBySchool = [];

        foreach ($schools as $i => $school) {
            $teacher = $teachers[$i];
            $students = [];

            // 8 students per school = 24 total.
            foreach (range(1, 8) as $n) {
                $user = User::factory()->create();

                $student = Student::factory()->create([
                    'user_id' => $user->id,
                    'home_school_id' => $school->id,
                    'voice_part_id' => VoicePart::query()->inRandomOrder()->first()?->id,
                ]);

                SchoolStudent::factory()->create([
                    'student_id' => $student->id,
                    'school_id' => $school->id,
                    'is_active' => true,
                    'class_of' => fake()->numberBetween(2027, 2030),
                ]);

                StudentTeacher::factory()->create([
                    'student_id' => $student->id,
                    'teacher_id' => $teacher->id,
                    'school_id' => $school->id,
                    'subject' => Subject::Chorus,
                    'role' => TeacherRole::Primary,
                    'is_active' => true,
                ]);

                $students[] = $student;
            }

            $studentsBySchool[$i] = $students;
        }

        return $studentsBySchool;
    }

    /**
     * @param  array<int, School>  $schools
     * @param  array<int, array<int, Student>>  $studentsBySchool
     * @param  array<int, Teacher>  $teachers
     * @param  array<int, Ensemble>  $ensembles
     */
    private function buildClosedCycleCandidates(Version $version, array $schools, array $studentsBySchool, array $teachers, array $ensembles): void
    {
        // Use 6 of the 8 students per school in the closed cycle, leaving 2
        // per school untouched so a live demo can register a brand-new
        // candidate against the still-open active cycle without reusing
        // someone already seen in the completed one.
        $outcomes = [
            CandidateStatus::Accepted, CandidateStatus::Accepted, CandidateStatus::Accepted,
            CandidateStatus::Accepted, CandidateStatus::NotAccepted, CandidateStatus::NoShow,
        ];

        foreach ($studentsBySchool as $schoolIndex => $students) {
            $teacher = $teachers[$schoolIndex];
            $school = $schools[$schoolIndex];

            foreach (array_slice($students, 0, 6) as $i => $student) {
                $status = $outcomes[$i];

                $candidate = Candidate::factory()->create([
                    'student_id' => $student->id,
                    'version_id' => $version->id,
                    'school_id' => $school->id,
                    'teacher_id' => $teacher->id,
                    'voice_part_id' => $student->voice_part_id ?? VoicePart::query()->inRandomOrder()->first()?->id,
                    'status' => CandidateStatus::Eligible,
                ]);

                if ($status !== CandidateStatus::NoShow) {
                    Recording::create([
                        'version_id' => $version->id,
                        'candidate_id' => $candidate->id,
                        'file_type' => 'Solo',
                        'uploaded_by' => $student->user_id,
                        'approved_at' => now()->subMonths(5),
                        'approved_by' => $teacher->user_id,
                        'url' => 'https://example.com/sample-honor-choir/recordings/'.$candidate->id.'.mp3',
                    ]);
                }

                $ensembleId = null;

                if ($status === CandidateStatus::Accepted) {
                    $ensembleId = $ensembles[array_rand($ensembles)]->id;

                    AuditionResult::create([
                        'candidate_id' => $candidate->id,
                        'version_id' => $version->id,
                        'voice_part_id' => $candidate->voice_part_id,
                        'school_id' => $school->id,
                        'voice_part_order_by' => 1,
                        'score_count' => 3,
                        'total' => fake()->numberBetween(72, 100),
                    ]);
                } elseif ($status === CandidateStatus::NotAccepted) {
                    AuditionResult::create([
                        'candidate_id' => $candidate->id,
                        'version_id' => $version->id,
                        'voice_part_id' => $candidate->voice_part_id,
                        'school_id' => $school->id,
                        'voice_part_order_by' => 1,
                        'score_count' => 3,
                        'total' => fake()->numberBetween(30, 65),
                    ]);
                }

                $candidate->update([
                    'status' => $status,
                    'accepted_ensemble_id' => $ensembleId,
                ]);
            }
        }
    }

    /**
     * @param  array<int, School>  $schools
     * @param  array<int, array<int, Student>>  $studentsBySchool
     * @param  array<int, Teacher>  $teachers
     */
    private function buildActiveCycleCandidates(Version $version, array $schools, array $studentsBySchool, array $teachers): void
    {
        // The same 6-of-8 students per school who already have a completed
        // cycle behind them are re-registering for the new cycle, in early
        // registration states (nothing resolved yet).
        $statuses = [
            CandidateStatus::Registered, CandidateStatus::Registered, CandidateStatus::Registered,
            CandidateStatus::Pending, CandidateStatus::Eligible, CandidateStatus::Eligible,
        ];

        foreach ($studentsBySchool as $schoolIndex => $students) {
            $teacher = $teachers[$schoolIndex];
            $school = $schools[$schoolIndex];

            foreach (array_slice($students, 0, 6) as $i => $student) {
                Candidate::factory()->create([
                    'student_id' => $student->id,
                    'version_id' => $version->id,
                    'school_id' => $school->id,
                    'teacher_id' => $teacher->id,
                    'voice_part_id' => $student->voice_part_id ?? VoicePart::query()->inRandomOrder()->first()?->id,
                    'status' => $statuses[$i],
                ]);
            }
        }
    }

    /**
     * @param  array<int, Teacher>  $teachers
     */
    private function buildDemoLogins(VersionRoleService $versionRoles, User $eventManager, array $teachers, Version $closedVersion, Version $activeVersion): void
    {
        $versionRoles->withVersion($closedVersion, fn () => $eventManager->assignRole('Event Manager'));
        $versionRoles->withVersion($activeVersion, fn () => $eventManager->assignRole('Event Manager'));

        // The first teacher built (Dana Whitfield, Sample North High School)
        // doubles as the demo teacher login — already has an active,
        // verified school link and a full roster of students/candidates.
        $demoTeacherUser = $teachers[0]->user;
        $demoTeacherUser->update([
            'email' => 'demo.teacher@sample-honorchoir.example',
            'password' => Hash::make(self::DEMO_PASSWORD),
        ]);
    }

    private function wipeExisting(): void
    {
        $organization = Organization::where('name', self::ORG_NAME)->first();

        if ($organization !== null) {
            $eventIds = Event::withTrashed()->where('organization_id', $organization->id)->pluck('id');
            $versionIds = Version::withTrashed()->whereIn('event_id', $eventIds)->pluck('id');
            $candidateIds = Candidate::whereIn('version_id', $versionIds)->pluck('id');

            CandidateStatusHistory::whereIn('candidate_id', $candidateIds)->delete();
            Recording::whereIn('candidate_id', $candidateIds)->delete();
            AuditionResult::whereIn('candidate_id', $candidateIds)->delete();
            Candidate::whereIn('id', $candidateIds)->delete();

            VersionDate::whereIn('version_id', $versionIds)->delete();
            VersionFee::whereIn('version_id', $versionIds)->delete();
            Version::withTrashed()->whereIn('id', $versionIds)->forceDelete();

            $ensembleIds = Ensemble::withTrashed()->whereIn('event_id', $eventIds)->pluck('id');
            EnsembleGrade::whereIn('ensemble_id', $ensembleIds)->delete();
            DB::table('ensemble_voice_parts')->whereIn('ensemble_id', $ensembleIds)->delete();
            Ensemble::withTrashed()->whereIn('id', $ensembleIds)->forceDelete();

            Event::withTrashed()->whereIn('id', $eventIds)->forceDelete();
        }

        $schoolIds = School::whereIn('name', self::SCHOOL_NAMES)->pluck('id');

        if ($schoolIds->isNotEmpty()) {
            $teacherIds = DB::table('school_teacher')->whereIn('school_id', $schoolIds)->pluck('teacher_id')->unique();
            $studentIds = DB::table('school_student')->whereIn('school_id', $schoolIds)->pluck('student_id')->unique();

            $teacherUserIds = Teacher::whereIn('id', $teacherIds)->pluck('user_id');
            $studentUserIds = Student::whereIn('id', $studentIds)->pluck('user_id');
            $demoUserIds = $teacherUserIds->merge($studentUserIds)->unique();

            DB::table('student_teacher')->whereIn('school_id', $schoolIds)->delete();
            DB::table('school_teacher')->whereIn('school_id', $schoolIds)->delete();
            DB::table('school_student')->whereIn('school_id', $schoolIds)->delete();

            Student::whereIn('id', $studentIds)->delete();
            Teacher::whereIn('id', $teacherIds)->delete();

            DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $demoUserIds)->delete();
            User::whereIn('id', $demoUserIds)->delete();

            School::whereIn('id', $schoolIds)->delete();
        }

        // The demo Event Manager login is outside any school, so wipe it by email.
        $eventManager = User::where('email', 'demo.eventmanager@sample-honorchoir.example')->first();

        if ($eventManager !== null) {
            DB::table('model_has_roles')->where('model_type', User::class)->where('model_id', $eventManager->id)->delete();
            $eventManager->delete();
        }

        if ($organization !== null) {
            $organization->delete();
        }
    }
}
