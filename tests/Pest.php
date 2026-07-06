<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Grants $user the given version-scoped role for $version, restoring
 * whatever permissions team context was active beforehand.
 */
function grantVersionRole(\App\Models\User $user, \App\Models\Version $version, string $role): void
{
    app(\App\Services\VersionRoleService::class)->withVersion($version, fn () => $user->assignRole($role));
}

/**
 * Returns a User whose email satisfies App\Models\User::isFounder(). The
 * gitignored local seed data (database/seeders/data/users.csv) may already
 * contain a row for the real Founder email, since $seed = true runs the
 * full DatabaseSeeder before every test — reuse it rather than colliding
 * with it under the email column's unique constraint.
 */
function makeFounder(): \App\Models\User
{
    return \App\Models\User::where('email', 'rick@mfrholdings.com')->first()
        ?? \App\Models\User::factory()->create(['email' => 'rick@mfrholdings.com']);
}
