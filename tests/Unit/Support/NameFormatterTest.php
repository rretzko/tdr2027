<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\NameFormatter;

function makeUser(array $nameParts): User
{
    $user = new User;
    $user->forceFill($nameParts);

    return $user;
}

test('buildDisplayName joins present name parts with spaces', function () {
    $user = makeUser([
        'honorific' => 'Dr.',
        'first_name' => 'Jane',
        'middle_name' => 'Q',
        'last_name' => 'Smith',
        'suffix_name' => 'Jr.',
    ]);

    expect(NameFormatter::buildDisplayName($user))->toBe('Dr. Jane Q Smith Jr.');
});

test('buildDisplayName omits empty optional name parts', function () {
    $user = makeUser([
        'honorific' => null,
        'first_name' => 'Jane',
        'middle_name' => null,
        'last_name' => 'Smith',
        'suffix_name' => null,
    ]);

    expect(NameFormatter::buildDisplayName($user))->toBe('Jane Smith');
});

test('buildSortName formats as "Last, Suffix, First Middle (Honorific)"', function () {
    $user = makeUser([
        'honorific' => 'Dr.',
        'first_name' => 'Jane',
        'middle_name' => 'Q',
        'last_name' => 'Smith',
        'suffix_name' => 'Jr.',
    ]);

    expect(NameFormatter::buildSortName($user))->toBe('Smith, Jr., Jane Q (Dr.)');
});

test('buildSortName omits optional segments when absent', function () {
    $user = makeUser([
        'honorific' => null,
        'first_name' => 'Jane',
        'middle_name' => null,
        'last_name' => 'Smith',
        'suffix_name' => null,
    ]);

    expect(NameFormatter::buildSortName($user))->toBe('Smith, Jane');
});
