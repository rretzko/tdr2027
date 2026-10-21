<?php

declare(strict_types=1);

use App\Enums\UnsubscribableEmail;
use App\Livewire\Settings\EmailPreferences;
use App\Models\EmailUnsubscribe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

test('opening the unsubscribe link only asks for confirmation', function () {
    $user = User::factory()->create();

    get($user->unsubscribeUrl(UnsubscribableEmail::DirectorWeeklyUpdate))
        ->assertOk()
        ->assertSee('Unsubscribe from the Director Weekly Update?');

    expect($user->isUnsubscribedFrom(UnsubscribableEmail::DirectorWeeklyUpdate))->toBeFalse();
});

test('confirming unsubscribes from that email type only', function () {
    $user = User::factory()->create();

    post($user->unsubscribeUrl(UnsubscribableEmail::DirectorWeeklyUpdate))
        ->assertOk()
        ->assertSee("You're unsubscribed", false);

    expect($user->isUnsubscribedFrom(UnsubscribableEmail::DirectorWeeklyUpdate))->toBeTrue()
        ->and($user->isUnsubscribedFrom(UnsubscribableEmail::MondayScorecard))->toBeFalse();
});

test('a mailbox provider one-click unsubscribe works without a CSRF token', function () {
    $user = User::factory()->create();

    post($user->unsubscribeUrl(UnsubscribableEmail::MondayScorecard), ['List-Unsubscribe' => 'One-Click'])
        ->assertOk();

    expect($user->isUnsubscribedFrom(UnsubscribableEmail::MondayScorecard))->toBeTrue();
});

test('unsubscribing twice is harmless', function () {
    $user = User::factory()->create();
    $url = $user->unsubscribeUrl(UnsubscribableEmail::MondayScorecard);

    post($url)->assertOk();
    post($url)->assertOk();

    expect(EmailUnsubscribe::where('user_id', $user->id)->count())->toBe(1);
});

test('a tampered link is rejected', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $tampered = str_replace("/{$user->id}/", "/{$other->id}/", $user->unsubscribeUrl(UnsubscribableEmail::MondayScorecard));

    post($tampered)->assertForbidden();

    expect($other->isUnsubscribedFrom(UnsubscribableEmail::MondayScorecard))->toBeFalse();
});

test('email preferences shows each digest as subscribed by default', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(EmailPreferences::class)
        ->assertSet('subscribed.director_weekly_update', true)
        ->assertSet('subscribed.monday_scorecard', true);
});

test('email preferences unsubscribes and resubscribes', function () {
    $user = User::factory()->create();
    EmailUnsubscribe::create(['user_id' => $user->id, 'email_type' => UnsubscribableEmail::DirectorWeeklyUpdate]);

    Livewire::actingAs($user)
        ->test(EmailPreferences::class)
        ->assertSet('subscribed.director_weekly_update', false)
        ->set('subscribed.director_weekly_update', true)
        ->set('subscribed.monday_scorecard', false);

    expect($user->isUnsubscribedFrom(UnsubscribableEmail::DirectorWeeklyUpdate))->toBeFalse()
        ->and($user->isUnsubscribedFrom(UnsubscribableEmail::MondayScorecard))->toBeTrue();
});
