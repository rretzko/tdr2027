<?php

declare(strict_types=1);

use App\Livewire\Founder\Issues;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('a non-founder cannot view the issues page', function () {
    $user = User::factory()->create();

    actingAs($user)->get(route('founder.issues'))->assertNotFound();
});

test('the founder sees feedback from every user', function () {
    $founder = makeFounder();
    $reporter = User::factory()->create();

    Feedback::factory()->create(['user_id' => $reporter->id, 'request' => 'Please add dark mode toggle here']);

    Livewire::actingAs($founder)
        ->test(Issues::class)
        ->assertSee('Please add dark mode toggle here')
        ->assertSee($reporter->first_name);
});

test('updateStatus changes a feedback row\'s status', function () {
    $founder = makeFounder();
    $feedback = Feedback::factory()->create(['status' => 'open']);

    Livewire::actingAs($founder)
        ->test(Issues::class)
        ->call('updateStatus', $feedback->id, 'resolved');

    expect($feedback->fresh()->getRawOriginal('status'))->toBe('resolved');
});

test('typeFilter narrows the issues list to the selected request type', function () {
    $founder = makeFounder();

    Feedback::factory()->create(['request_type' => 'bug', 'request' => 'Bug report text']);
    Feedback::factory()->create(['request_type' => 'kudo', 'request' => 'Kudo message text']);

    Livewire::actingAs($founder)
        ->test(Issues::class)
        ->set('typeFilter', 'bug')
        ->assertSee('Bug report text')
        ->assertDontSee('Kudo message text');
});
