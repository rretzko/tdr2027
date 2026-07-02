<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Organization;
use App\Models\Pivots\TeacherSupervisor;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('the dashboard shows a no-open-events message when there are none', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Events')
        ->assertSeeText('No open events.');
});

test('the dashboard lists open events from linked organizations', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $teacher = Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    $organization = Organization::factory()->create();
    TeacherSupervisor::create(['organization_id' => $organization->id, 'teacher_id' => $teacher->id]);
    Event::factory()->active()->create(['organization_id' => $organization->id, 'name' => 'Spring Showcase']);
    Event::factory()->create(['organization_id' => $organization->id, 'name' => 'Closed Event']);

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Spring Showcase')
        ->assertDontSeeText('Closed Event');
});

test('the dashboard does not show events from organizations the teacher is not linked to', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    $organization = Organization::factory()->create();
    Event::factory()->active()->create(['organization_id' => $organization->id, 'name' => 'Unrelated Event']);

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSeeText('Unrelated Event')
        ->assertSeeText('No open events.');
});
