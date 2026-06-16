<?php

declare(strict_types=1);

use App\Livewire\Settings\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('profile page is displayed', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->get('/settings/profile')
        ->assertOk();
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
