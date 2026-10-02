<?php

use App\Enums\ReadinessStatus;
use App\Enums\VersionApplicationStatus;
use App\Enums\VersionDateType;
use App\Enums\VersionInvitationStatus;
use App\Models\Ensemble;
use App\Models\EnsembleGrade;
use App\Models\Event;
use App\Models\Organization;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionApplication;
use App\Models\VersionDate;
use App\Models\VersionFee;
use App\Models\VersionInvitation;
use App\Models\VersionMailToAddress;
use App\Models\VoicePart;
use App\Services\Readiness\VersionReadiness;
use App\Services\VersionRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Grants $user the given version-scoped role for $version, restoring
 * whatever permissions team context was active beforehand.
 */
function grantVersionRole(User $user, Version $version, string $role): void
{
    app(VersionRoleService::class)->withVersion($version, fn () => $user->assignRole($role));
}

/**
 * Returns a User whose email satisfies App\Models\User::isFounder(). The
 * gitignored local seed data (database/seeders/data/users.csv) may already
 * contain a row for the real Founder email, since $seed = true runs the
 * full DatabaseSeeder before every test — reuse it rather than colliding
 * with it under the email column's unique constraint.
 */
function makeFounder(): User
{
    return User::where('email', 'rick@mfrholdings.com')->first()
        ?? User::factory()->create(['email' => 'rick@mfrholdings.com']);
}

/*
| Version readiness fixtures (docs/plans/version-readiness.md) — shared by
| the service and Livewire readiness tests. Tests using these should freeze
| time (Carbon::setTestNow) so the "current graduating class" is stable.
*/

function readinessVersion(array $attributes = []): Version
{
    $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    return Version::factory()->create(['event_id' => $event->id, 'senior_class_of' => 2027, ...$attributes]);
}

function readinessStatus(Version $version, string $key): ReadinessStatus
{
    return app(VersionReadiness::class)->evaluate($version->fresh())->get($key)->status;
}

function readinessSetDate(Version $version, VersionDateType $type, bool $withEnd = true): void
{
    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => $type->value,
        'start_at' => '2026-11-01 08:00:00',
        'end_at' => $withEnd && $type->hasEndAt() ? '2026-12-01 17:00:00' : null,
    ]);
}

/**
 * A Version with every blocking item up to and including
 * BeforeRegistration complete (PDF application, remote, no uploads, no e-pay).
 */
function readinessConfiguredVersion(): Version
{
    $version = readinessVersion();

    $ensemble = Ensemble::factory()->create(['event_id' => $version->event_id]);
    EnsembleGrade::create(['ensemble_id' => $ensemble->id, 'grade' => 11]);
    $ensemble->voiceParts()->attach(VoicePart::factory()->create()->id);

    $category = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Tone', 'order_by' => 1]);
    ScoreFactor::create(['event_id' => $version->event_id, 'score_category_id' => $category->id, 'description' => 'Quality', 'abbreviation' => 'Q', 'best' => 1, 'worst' => 9]);

    readinessSetDate($version, VersionDateType::Teacher);
    readinessSetDate($version, VersionDateType::Candidate);
    readinessSetDate($version, VersionDateType::PostmarkDeadline);
    VersionFee::create(['version_id' => $version->id]);

    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => Teacher::factory()->create()->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    VersionApplication::create([
        'version_id' => $version->id,
        'student_endorsement_body' => 'Student',
        'parent_endorsement_body' => 'Parent',
        'teacher_principal_endorsement_body' => 'Teacher',
        'status' => VersionApplicationStatus::Published->value,
        'published_at' => now(),
    ]);

    $manager = User::factory()->create();
    grantVersionRole($manager, $version, 'Registration Manager');
    VersionMailToAddress::factory()->create(['version_id' => $version->id, 'user_id' => $manager->id]);

    return $version;
}
