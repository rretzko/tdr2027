<?php

declare(strict_types=1);

use App\Livewire\Dashboard\TourDismiss;
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
