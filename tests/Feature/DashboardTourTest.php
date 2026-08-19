<?php

declare(strict_types=1);

use App\Livewire\Dashboard\TourDismiss;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeDashboardTourUser(): User
{
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    return $user;
}

/**
 * An active school is required or EnsureStudentHasActiveSchool redirects
 * every real HTTP request to /dashboard away to /sfdi/school before this
 * test ever sees the tour markup (studentfolder-module.md §7) — unlike the
 * Sfdi ShowTest.php tests, which drive the Livewire component directly and
 * never touch that middleware at all.
 */
function makeDashboardTourStudentUser(): User
{
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $student = Student::factory()->create(['user_id' => $user->id]);
    $student->schools()->attach(School::factory()->create()->id, ['is_active' => true, 'class_of' => 2030]);

    return $user;
}

test('the Take a tour button auto-starts for a user who has never taken it', function () {
    $user = makeDashboardTourUser();

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Take a tour')
        ->assertSeeHtml('data-auto-start="1"');
});

test('the Take a tour button does not auto-start once the tour has already been taken', function () {
    $user = makeDashboardTourUser();
    $user->update(['dismissed_dashboard_orientation_at' => now()]);

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeHtml('data-auto-start="0"');
});

test('the sidebar and card tour anchors render for a fully onboarded teacher', function () {
    $user = makeDashboardTourUser();

    $response = actingAs($user)->get(route('dashboard'))->assertOk();

    foreach ([
        'id="tour-sidebar-dashboard"',
        'id="tour-sidebar-fastpass"',
        'id="tour-sidebar-schools"',
        'id="tour-sidebar-organizations"',
        'id="tour-sidebar-profile"',
        'id="tour-sidebar-password"',
        'id="tour-sidebar-appearance"',
        'id="tour-sidebar-logout"',
        'id="tour-card-schools"',
        'id="tour-card-students"',
        'id="tour-card-organizations"',
        'id="tour-card-events"',
    ] as $needle) {
        $response->assertSeeHtml($needle);
    }
});

test('TourDismiss persists the dismissal for the acting user', function () {
    $user = makeDashboardTourUser();

    Livewire::actingAs($user)
        ->test(TourDismiss::class)
        ->call('dismiss');

    expect($user->fresh()->dismissed_dashboard_orientation_at)->not->toBeNull();
});

// --- StudentFolder.info side (studentfolder-module.md, "take a tour" build) ---

test('the Take a tour button auto-starts for a student who has never taken it', function () {
    $user = makeDashboardTourStudentUser();

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Take a tour')
        ->assertSeeHtml('data-auto-start="1"');
});

test('the Take a tour button does not auto-start once a student has already taken it', function () {
    $user = makeDashboardTourStudentUser();
    $user->update(['dismissed_dashboard_orientation_at' => now()]);

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeHtml('data-auto-start="0"');
});

test('the sidebar and card tour anchors render for a student, with no teacher-only anchors', function () {
    $user = makeDashboardTourStudentUser();

    $response = actingAs($user)->get(route('dashboard'))->assertOk();

    foreach ([
        'id="tour-sidebar-dashboard"',
        'id="tour-sidebar-my-events"',
        'id="tour-sidebar-student-details"',
        'id="tour-sidebar-school"',
        'id="tour-sidebar-emergency-contacts"',
        'id="tour-sidebar-profile"',
        'id="tour-sidebar-password"',
        'id="tour-sidebar-appearance"',
        'id="tour-sidebar-logout"',
        'id="tour-my-events-card"',
    ] as $needle) {
        $response->assertSeeHtml($needle);
    }

    // Same shared dismissed_dashboard_orientation_at flag/dash-tour-* dismiss
    // trigger as the teacher variant (§7 — no co-role analog), but the
    // teacher-only sidebar/card anchors must never render for a student.
    foreach (['id="tour-sidebar-fastpass"', 'id="tour-sidebar-schools"', 'id="tour-card-events"'] as $needle) {
        $response->assertDontSeeHtml($needle);
    }
});

test('TourDismiss persists the dismissal for a student', function () {
    $user = makeDashboardTourStudentUser();

    Livewire::actingAs($user)
        ->test(TourDismiss::class)
        ->call('dismiss');

    expect($user->fresh()->dismissed_dashboard_orientation_at)->not->toBeNull();
});
