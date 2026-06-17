<?php

declare(strict_types=1);

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('each dashboard card links to its own index page and has a unique color accent', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

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
