<?php

declare(strict_types=1);

use App\Livewire\Settings\Profile;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('profile page is displayed', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->get('/settings/profile')
        ->assertOk();
});

test('a teacher sees the Honorific field', function () {
    $user = User::factory()->create();
    Teacher::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->assertSee('Honorific');
});

test('a student does not see the Honorific field', function () {
    $user = User::factory()->create();
    Student::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->assertDontSee('Honorific');
});

test('profile information can be updated', function () {
    Notification::fake();

    $user = User::factory()->create([
        'first_name' => 'Old',
        'last_name' => 'Name',
        'pronoun_id' => 1,
    ]);

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('first_name', 'New')
        ->set('last_name', 'Name')
        ->set('email', $user->email)
        ->set('pronoun_id', '2')
        ->set('cell_phone', $user->cell_phone)
        ->call('update')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->first_name)->toBe('New');
    expect($user->pronoun_id)->toBe(2);
});

// --- Profile photo upload ---

test('uploading a photo stores it under thumbnails/ on S3 and replaces the placeholder icon', function () {
    Storage::fake('s3');

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('photo', UploadedFile::fake()->image('headshot.jpg', 300, 300))
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->photo_path)->not->toBeNull();
    expect($user->photo_path)->toStartWith('thumbnails/');
    Storage::disk('s3')->assertExists($user->photo_path);
});

test('uploading a new photo deletes the previous one from S3', function () {
    Storage::fake('s3');

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('photo', UploadedFile::fake()->image('first.jpg'));

    $firstPath = $user->refresh()->photo_path;

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('photo', UploadedFile::fake()->image('second.jpg'));

    $secondPath = $user->refresh()->photo_path;

    expect($secondPath)->not->toBe($firstPath);
    Storage::disk('s3')->assertMissing($firstPath);
    Storage::disk('s3')->assertExists($secondPath);
});

test('removePhoto clears the photo and deletes the S3 object', function () {
    Storage::fake('s3');

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('photo', UploadedFile::fake()->image('headshot.jpg'));

    $path = $user->refresh()->photo_path;

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->call('removePhoto');

    expect($user->refresh()->photo_path)->toBeNull();
    Storage::disk('s3')->assertMissing($path);
});

test('a non-image file is rejected', function () {
    Storage::fake('s3');

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('photo', UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'))
        ->assertHasErrors(['photo']);

    expect($user->refresh()->photo_path)->toBeNull();
});
