<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

test('student guide redirects to a signed S3 URL for any authenticated user', function () {
    Storage::fake('s3');
    actingAs(User::factory()->create(['email_verified_at' => now()]));

    get(route('guides.show', 'student-guide'))->assertRedirect();
});

test('teacher guide redirects to a signed S3 URL for any authenticated user', function () {
    Storage::fake('s3');
    actingAs(User::factory()->create(['email_verified_at' => now()]));

    get(route('guides.show', 'teacher-guide'))->assertRedirect();
});

test('unknown guide 404s', function () {
    actingAs(User::factory()->create(['email_verified_at' => now()]));

    get(route('guides.show', 'made-up-guide'))->assertStatus(404);
});

test('event manager guide 403s for a user with no active or sandbox version role', function () {
    actingAs(User::factory()->create(['email_verified_at' => now()]));

    get(route('guides.show', 'event-manager-guide'))->assertStatus(403);
});

test('event manager guide 403s when the only version role is on a closed version', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'closed']);
    grantVersionRole($user, $version, 'Event Manager');

    actingAs($user);

    get(route('guides.show', 'event-manager-guide'))->assertStatus(403);
});

test('event manager guide redirects to a signed S3 URL for a sandbox version role', function () {
    Storage::fake('s3');

    $user = User::factory()->create(['email_verified_at' => now()]);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);
    grantVersionRole($user, $version, 'Event Manager');

    actingAs($user);

    get(route('guides.show', 'event-manager-guide'))->assertRedirect();
});

test('event manager guide redirects to a signed S3 URL for an active version role', function () {
    Storage::fake('s3');

    $user = User::factory()->create(['email_verified_at' => now()]);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    grantVersionRole($user, $version, 'Tab Room Manager');

    actingAs($user);

    get(route('guides.show', 'event-manager-guide'))->assertRedirect();
});
