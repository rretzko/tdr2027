<?php

declare(strict_types=1);

use App\Enums\VersionObligationStatus;
use App\Livewire\Registrations\EstimateForm;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VersionObligation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeEstimateFormTeacher(): Teacher
{
    $user = User::factory()->create();

    return Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
}

test('mount aborts with 403 for a teacher with no invitation on this Version', function () {
    $teacher = makeEstimateFormTeacher();
    $version = Version::factory()->create();
    actingAs($teacher->user);

    Livewire::test(EstimateForm::class, ['version' => $version])
        ->assertStatus(403);
});

test('mount redirects an invited teacher who has not yet responded to a published obligation', function () {
    $teacher = makeEstimateFormTeacher();
    $version = Version::factory()->create();
    actingAs($teacher->user);
    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => 'invited',
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);
    VersionObligation::create([
        'version_id' => $version->id,
        'body' => '<p>Be excellent.</p>',
        'status' => VersionObligationStatus::Published->value,
        'published_at' => now(),
        'published_by_user_id' => User::factory()->create()->id,
    ]);

    Livewire::test(EstimateForm::class, ['version' => $version])
        ->assertRedirect(route('registrations.obligations', $version));
});

test('an invited teacher sees the placeholder', function () {
    $teacher = makeEstimateFormTeacher();
    $version = Version::factory()->create(['name' => 'Fall Auditions']);
    actingAs($teacher->user);
    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => 'invited',
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    Livewire::test(EstimateForm::class, ['version' => $version])
        ->assertSee('Fall Auditions')
        ->assertSee('Estimate Form')
        ->assertSee('check back soon');
});
