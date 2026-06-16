<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Pivots\TeacherSupervisor;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('the dashboard shows an Organizations card with count and abbr for each organization', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $teacher = Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    $orgA = Organization::factory()->create(['name' => 'New Jersey Music Educators Association', 'abbr' => 'NJMEA']);
    $orgB = Organization::factory()->create(['name' => 'Central Jersey Music Educators Association', 'abbr' => 'CJMEA']);

    foreach ([$orgA, $orgB] as $organization) {
        TeacherSupervisor::create(['organization_id' => $organization->id, 'teacher_id' => $teacher->id]);
    }

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Organizations')
        ->assertSeeText('2 organizations')
        ->assertSeeText('NJMEA')
        ->assertSeeText('CJMEA');
});

test('the Organizations card falls back to the name when an organization has no abbr', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $teacher = Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    $organization = Organization::factory()->create(['name' => 'Morric County Choral Directors Association', 'abbr' => null]);
    TeacherSupervisor::create(['organization_id' => $organization->id, 'teacher_id' => $teacher->id]);

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('1 organization')
        ->assertSeeText('Morric County Choral Directors Association');
});

test('the Organizations card shows a no-organizations message when none are linked', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('0 organizations')
        ->assertSeeText('No organizations linked yet.');
});
