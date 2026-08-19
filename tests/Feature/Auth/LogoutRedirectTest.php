<?php

declare(strict_types=1);

use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

test('logging out redirects a student to the SFDI splash page, not the TDR one', function () {
    $user = User::factory()->create();
    Student::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    post(route('logout'))->assertRedirect(route('sfdi.welcome'));
});

test('logging out redirects a teacher to the TDR splash page', function () {
    $user = User::factory()->create();
    Teacher::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    post(route('logout'))->assertRedirect('/');
});
