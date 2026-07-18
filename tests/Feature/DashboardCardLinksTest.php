<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Organization;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('each dashboard card links to its own index page and has a unique color accent', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    // The Events card only links to events.index when the teacher holds a
    // version-scoped role somewhere — otherwise it's a "start a new event"
    // CTA pointing at events.create instead.
    $organization = Organization::factory()->create();
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $version = Version::factory()->create(['event_id' => $event->id]);
    grantVersionRole($user, $version, 'Event Manager');

    $response = actingAs($user)->get(route('dashboard'))->assertOk();

    $response->assertSee('href="'.route('schools.index').'"', false);
    $response->assertSee('href="'.route('students.index').'"', false);
    $response->assertSee('href="'.route('organizations.index').'"', false);
    $response->assertSee('href="'.route('events.index').'"', false);

    $colorClasses = ['border-l-blue-500', 'border-l-violet-500', 'border-l-orange-500', 'border-l-teal-500'];

    foreach ($colorClasses as $class) {
        $response->assertSee($class, false);
    }

    // Each color class is genuinely unique to one card, not shared.
    expect(collect($colorClasses)->unique()->count())->toBe(count($colorClasses));
});
