<?php

declare(strict_types=1);

use App\Livewire\Feedback\Index;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('s3');
});

test('submit creates a feedback row owned by the current user', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('request_type', 'bug')
        ->set('request', 'The submit button is unresponsive.')
        ->call('submit')
        ->assertHasNoErrors();

    $feedback = Feedback::first();

    expect($feedback->user_id)->toBe($user->id)
        ->and($feedback->getRawOriginal('request_type'))->toBe('bug')
        ->and($feedback->request)->toBe('The submit button is unresponsive.')
        ->and($feedback->is_private)->toBeFalse()
        ->and($feedback->getRawOriginal('status'))->toBe('open');
});

test('submit requires a request type and description', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('request_type', '')
        ->set('request', '')
        ->call('submit')
        ->assertHasErrors(['request_type', 'request']);
});

test('submit stores an uploaded file on the s3 disk', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('screenshot.png');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('request_type', 'bug')
        ->set('request', 'See attached.')
        ->set('newFile', $file)
        ->call('submit')
        ->assertHasNoErrors();

    $feedback = Feedback::first();

    expect($feedback->file_path)->not->toBeNull();
    Storage::disk('s3')->assertExists($feedback->file_path);
});

test('history only shows the current user\'s own feedback', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Feedback::factory()->create(['user_id' => $user->id, 'request' => 'Mine']);
    Feedback::factory()->create(['user_id' => $otherUser->id, 'request' => 'Not mine']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('activeTab', 'history')
        ->assertSee('Mine')
        ->assertDontSee('Not mine');
});

test('history excludes the user\'s own private feedback', function () {
    $user = User::factory()->create();

    Feedback::factory()->create(['user_id' => $user->id, 'request' => 'Public one', 'is_private' => false]);
    Feedback::factory()->create(['user_id' => $user->id, 'request' => 'Private one', 'is_private' => true]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('activeTab', 'history')
        ->assertSee('Public one')
        ->assertDontSee('Private one');
});
