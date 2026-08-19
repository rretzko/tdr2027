<?php

declare(strict_types=1);

use App\Livewire\Organizations\Index;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeOrganizationsIndexTeacher(): Teacher
{
    $user = User::factory()->create();

    return Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
}

test('uploading a membership card stores it on the s3 disk, not public', function () {
    Storage::fake('s3');

    $teacher = makeOrganizationsIndexTeacher();
    $organization = Organization::factory()->create(['parent_id' => null]);

    Livewire::actingAs($teacher->user)
        ->test(Index::class)
        ->set('selectedOrganizationIds', [$organization->id])
        ->set('membershipCards.'.$organization->id, UploadedFile::fake()->image('card.jpg'))
        ->call('save')
        ->assertHasNoErrors();

    $membership = Membership::where('teacher_id', $teacher->id)->where('organization_id', $organization->id)->first();

    expect($membership)->not->toBeNull();
    expect($membership->membership_card)->toStartWith('memberships/cards/');
    Storage::disk('s3')->assertExists($membership->membership_card);
});

test('a teacher can remove their membership card, deleting the S3 object immediately', function () {
    Storage::fake('s3');

    $teacher = makeOrganizationsIndexTeacher();
    $organization = Organization::factory()->create(['parent_id' => null]);
    Storage::disk('s3')->put('memberships/cards/existing.jpg', 'fake-bytes');
    $membership = Membership::factory()->create([
        'teacher_id' => $teacher->id,
        'organization_id' => $organization->id,
        'membership_card' => 'memberships/cards/existing.jpg',
    ]);

    Livewire::actingAs($teacher->user)
        ->test(Index::class)
        ->set('selectedOrganizationIds', [$organization->id])
        ->call('removeMembershipCard', $organization->id);

    expect($membership->refresh()->membership_card)->toBeNull();
    Storage::disk('s3')->assertMissing('memberships/cards/existing.jpg');
});

test('a teacher cannot remove another teacher\'s membership card', function () {
    Storage::fake('s3');

    $teacher = makeOrganizationsIndexTeacher();
    $otherTeacher = makeOrganizationsIndexTeacher();
    $organization = Organization::factory()->create(['parent_id' => null]);
    Storage::disk('s3')->put('memberships/cards/existing.jpg', 'fake-bytes');
    $otherMembership = Membership::factory()->create([
        'teacher_id' => $otherTeacher->id,
        'organization_id' => $organization->id,
        'membership_card' => 'memberships/cards/existing.jpg',
    ]);

    Livewire::actingAs($teacher->user)
        ->test(Index::class)
        ->call('removeMembershipCard', $organization->id);

    expect($otherMembership->refresh()->membership_card)->toBe('memberships/cards/existing.jpg');
    Storage::disk('s3')->assertExists('memberships/cards/existing.jpg');
});
