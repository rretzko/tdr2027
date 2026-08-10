<?php

declare(strict_types=1);

use App\Mail\AuditionResultsAvailableMail;
use App\Models\Event;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('envelope subject names the Version and content links to the Results page, signed by the closing manager', function () {
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'name' => 'Fall Chorus Auditions']);
    $manager = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Smith']);

    $mail = new AuditionResultsAvailableMail($version, $manager);

    expect($mail->envelope()->subject)->toBe('Audition results are available for Fall Chorus Auditions');

    $html = $mail->render();
    expect($html)->toContain('Fall Chorus Auditions');
    expect($html)->toContain(route('registrations.results', $version));
    expect($html)->toContain('Jane Smith, Event Manager Fall Chorus Auditions');
});
