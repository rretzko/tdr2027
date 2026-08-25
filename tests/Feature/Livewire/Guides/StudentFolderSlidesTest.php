<?php

declare(strict_types=1);

use App\Livewire\Guides\StudentFolderSlides;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Storage::fake('s3');
    Storage::disk('s3')->buildTemporaryUrlsUsing(
        fn (string $path, $expiration, array $options = []) => 'https://fake-s3.test/'.$path.(
            isset($options['ResponseContentDisposition']) ? '?ResponseContentDisposition='.urlencode($options['ResponseContentDisposition']) : ''
        )
    );

    foreach ([
        'sfdi-01-welcome.png',
        'sfdi-02-registration.png',
        'sfdi-03-dashboard.png',
        'sfdi-04-profile.png',
        'sfdi-05-password.png',
        'sfdi-06-student-details.png',
        'sfdi-07-school.png',
        'sfdi-08-emergency-contacts.png',
        'sfdi-09-my-events-1.png',
        'sfdi-10-my-events-2.png',
    ] as $file) {
        Storage::disk('s3')->put("libraries/sfdi-slides/{$file}", 'fake-image-bytes');
    }
});

function makeStudentFolderSlidesVersion(): Version
{
    $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    return Version::factory()->create(['event_id' => $event->id]);
}

test('mount aborts with 403 for a user with no active or sandbox version-scoped role', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StudentFolderSlides::class)
        ->assertStatus(403);
});

test('mount loads all slides in filename order with titles derived from filenames', function () {
    $user = User::factory()->create();
    $version = makeStudentFolderSlidesVersion();
    grantVersionRole($user, $version, 'Event Manager');

    $component = Livewire::actingAs($user)->test(StudentFolderSlides::class);

    $component->assertOk();
    expect($component->get('slides'))->toHaveCount(10);
    expect($component->get('slides')[0]['title'])->toBe('Welcome');
    expect($component->get('slides')[9]['title'])->toBe('My Events 2');
});

test('next, previous, and goTo clamp within slide bounds', function () {
    $user = User::factory()->create();
    $version = makeStudentFolderSlidesVersion();
    grantVersionRole($user, $version, 'Event Manager');

    $component = Livewire::actingAs($user)->test(StudentFolderSlides::class);

    $component->call('previous');
    expect($component->get('current'))->toBe(0);

    $component->call('goTo', 999);
    expect($component->get('current'))->toBe(9);

    $component->call('next');
    expect($component->get('current'))->toBe(9);

    $component->call('goTo', -5);
    expect($component->get('current'))->toBe(0);
});

test('downloadUrl requests an attachment content disposition', function () {
    $user = User::factory()->create();
    $version = makeStudentFolderSlidesVersion();
    grantVersionRole($user, $version, 'Event Manager');

    $component = Livewire::actingAs($user)->test(StudentFolderSlides::class);

    /** @var StudentFolderSlides $instance */
    $instance = $component->instance();

    expect($instance->downloadUrl())->toContain('ResponseContentDisposition');
});
