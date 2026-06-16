<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('name is computed from name parts on create', function () {
    $user = User::factory()->create([
        'honorific' => 'Dr.',
        'first_name' => 'Jane',
        'middle_name' => 'Q',
        'last_name' => 'Smith',
        'suffix_name' => 'Jr.',
    ]);

    expect($user->name)->toBe('Dr. Jane Q Smith Jr.');
});

test('name recomputes when a name part changes', function () {
    $user = User::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Smith',
    ]);

    $user->update(['first_name' => 'Janet']);

    expect($user->name)->toBe('Janet Smith');
});

test('name is unaffected by unrelated attribute changes', function () {
    $user = User::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Smith',
    ]);

    $user->update(['email_verified_at' => now()]);

    expect($user->name)->toBe('Jane Smith');
});

test('sort_name formats as "Last, Suffix, First Middle (Honorific)"', function () {
    $user = User::factory()->create([
        'honorific' => 'Dr.',
        'first_name' => 'Jane',
        'middle_name' => 'Q',
        'last_name' => 'Smith',
        'suffix_name' => 'Jr.',
    ]);

    expect($user->sort_name)->toBe('Smith, Jr., Jane Q (Dr.)');
});

test('sort_name omits optional segments when absent', function () {
    $user = User::factory()->create([
        'honorific' => null,
        'middle_name' => null,
        'suffix_name' => null,
        'first_name' => 'Jane',
        'last_name' => 'Smith',
    ]);

    expect($user->sort_name)->toBe('Smith, Jane');
});

test('pronoun_id is null when not specified', function () {
    $user = User::create([
        'first_name' => 'Default',
        'last_name' => 'Pronoun',
        'email' => 'default-pronoun@example.com',
        'password' => 'secret',
    ]);

    $user->refresh();

    expect($user->pronoun_id)->toBeNull();
    expect($user->pronoun)->toBeNull();
});

test('email must be unique', function () {
    User::factory()->create(['email' => 'duplicate@example.com']);

    expect(fn () => User::factory()->create(['email' => 'duplicate@example.com']))
        ->toThrow(QueryException::class);
});

test('first_name and last_name are required', function () {
    expect(fn () => User::create([
        'email' => 'noname@example.com',
        'password' => 'secret',
    ]))->toThrow(QueryException::class);
});

test('scopeOrdered orders by last name then first name', function () {
    User::factory()->create(['first_name' => 'Bob', 'last_name' => 'Zephyr', 'email' => 'bob@example.com']);
    User::factory()->create(['first_name' => 'Zoe', 'last_name' => 'Adams', 'email' => 'zoe@example.com']);
    User::factory()->create(['first_name' => 'Amy', 'last_name' => 'Adams', 'email' => 'amy@example.com']);

    $ordered = User::ordered()->get()->map(fn (User $user) => "{$user->last_name} {$user->first_name}")->values();

    $amyIndex = $ordered->search('Adams Amy');
    $zoeIndex = $ordered->search('Adams Zoe');
    $bobIndex = $ordered->search('Zephyr Bob');

    expect($amyIndex)->toBeLessThan($zoeIndex);
    expect($zoeIndex)->toBeLessThan($bobIndex);
});
