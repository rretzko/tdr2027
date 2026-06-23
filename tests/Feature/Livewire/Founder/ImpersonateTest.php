<?php

declare(strict_types=1);

use App\Livewire\Founder\Impersonate;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeFounderUser(): User
{
    // rick@mfrholdings.com may already exist from seeded data — reuse it rather
    // than colliding with the unique email constraint.
    return User::where('email', 'rick@mfrholdings.com')->first()
        ?? User::factory()->create(['email' => 'rick@mfrholdings.com']);
}

test('a non-founder cannot view the impersonate page', function () {
    $user = User::factory()->create();

    actingAs($user)->get(route('founder.impersonate'))->assertNotFound();
});

test('the founder can view the impersonate page', function () {
    $founder = makeFounderUser();

    actingAs($founder)->get(route('founder.impersonate'))->assertOk()->assertSeeText('Impersonate User');
});

test('no teachers are shown until a search term is entered', function () {
    $founder = makeFounderUser();
    $teacherUser = User::factory()->create(['first_name' => 'Jamie', 'last_name' => 'Lannister']);
    Teacher::factory()->create(['user_id' => $teacherUser->id]);

    Livewire::actingAs($founder)
        ->test(Impersonate::class)
        ->assertDontSee('Jamie')
        ->assertSee('Start typing a name to see matches.');
});

test('teachers can be found by first or last name', function () {
    $founder = makeFounderUser();
    $jamie = User::factory()->create(['first_name' => 'Jamie', 'last_name' => 'Lannister']);
    Teacher::factory()->create(['user_id' => $jamie->id]);
    $cersei = User::factory()->create(['first_name' => 'Cersei', 'last_name' => 'Lannister']);
    Teacher::factory()->create(['user_id' => $cersei->id]);
    $arya = User::factory()->create(['first_name' => 'Arya', 'last_name' => 'Stark']);
    Teacher::factory()->create(['user_id' => $arya->id]);

    Livewire::actingAs($founder)
        ->test(Impersonate::class)
        ->set('search', 'Lannister')
        ->assertSee('Jamie')
        ->assertSee('Cersei')
        ->assertDontSee('Arya');
});

test('search results show the teacher\'s active school', function () {
    $founder = makeFounderUser();
    $teacherUser = User::factory()->create(['first_name' => 'Jamie', 'last_name' => 'Lannister']);
    $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);
    $school = School::factory()->create(['name' => 'King\'s Landing Academy']);
    $teacher->schools()->attach($school, ['is_active' => true]);

    Livewire::actingAs($founder)
        ->test(Impersonate::class)
        ->set('search', 'Lannister')
        ->assertSee('King\'s Landing Academy');
});

test('a non-teacher user does not show up in the results', function () {
    $founder = makeFounderUser();
    User::factory()->create(['first_name' => 'Jamie', 'last_name' => 'Lannister']);

    Livewire::actingAs($founder)
        ->test(Impersonate::class)
        ->set('search', 'Lannister')
        ->assertDontSee('Jamie');
});

test('the founder can impersonate a teacher and is logged in as them', function () {
    $founder = makeFounderUser();
    $teacherUser = User::factory()->create(['first_name' => 'Jamie', 'last_name' => 'Lannister']);
    Teacher::factory()->create(['user_id' => $teacherUser->id, 'onboarding_completed_at' => now()]);

    Livewire::actingAs($founder)
        ->test(Impersonate::class)
        ->call('impersonate', $teacherUser->id)
        ->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($teacherUser->id);
    expect(session('impersonator_id'))->toBe($founder->id);
});

test('stopping impersonation logs the founder back in and clears the session flag', function () {
    $founder = makeFounderUser();
    $teacherUser = User::factory()->create();
    Teacher::factory()->create(['user_id' => $teacherUser->id, 'onboarding_completed_at' => now()]);

    actingAs($teacherUser)->withSession(['impersonator_id' => $founder->id])
        ->post(route('founder.stop-impersonating'))
        ->assertRedirect(route('founder.impersonate'));

    expect(auth()->id())->toBe($founder->id);
    expect(session()->has('impersonator_id'))->toBeFalse();
});

test('stopping impersonation without an active impersonation session is not found', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('founder.stop-impersonating'))->assertNotFound();
});
