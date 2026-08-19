<?php

declare(strict_types=1);

use App\Livewire\Sfdi\StudentDetails;
use App\Models\HomeAddress;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\VoicePart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeStudentDetailsUser(): User
{
    $user = User::factory()->create();
    Student::factory()->create(['user_id' => $user->id, 'voice_part_id' => null, 'birthday' => null, 'height' => null]);

    return $user;
}

test('a teacher without a student profile is forbidden', function () {
    $user = User::factory()->create();
    Teacher::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)->test(StudentDetails::class)->assertForbidden();
});

test('a student can save their biography details and home address', function () {
    $user = makeStudentDetailsUser();
    $voicePart = VoicePart::factory()->create();

    Livewire::actingAs($user)
        ->test(StudentDetails::class)
        ->set('voice_part_id', (string) $voicePart->id)
        ->set('birthday', '2012-05-01')
        ->set('shirt_size', 'lg')
        ->set('height', '60')
        ->set('home_address1', '123 Main St')
        ->set('home_city', 'Anytown')
        ->set('home_geo_state', 'nj')
        ->set('home_zip_code', '08901')
        ->call('update')
        ->assertHasNoErrors();

    $student = $user->student->fresh();

    expect($student->voice_part_id)->toBe($voicePart->id)
        ->and($student->getRawOriginal('shirt_size'))->toBe('lg')
        ->and($student->height)->toBe(60);

    $homeAddress = HomeAddress::where('student_id', $student->id)->first();

    expect($homeAddress)->not->toBeNull()
        ->and($homeAddress->geo_state)->toBe('NJ')
        ->and($homeAddress->zip_code)->toBe('08901');
});

test('the Height select shows the foot-inch translation alongside each inches value', function () {
    $user = makeStudentDetailsUser();

    // assertSeeHtml, not assertSee — assertSee HTML-escapes its needle
    // (looking for &#039;/&quot;), but the raw option text has plain
    // apostrophe/quote characters (feedback_flux_custom_label_fields-style
    // gotcha, cataloged project-wide).
    Livewire::actingAs($user)
        ->test(StudentDetails::class)
        ->assertSeeHtml('74 (6\'2")');
});

test('height outside the valid range is rejected', function () {
    $user = makeStudentDetailsUser();

    Livewire::actingAs($user)
        ->test(StudentDetails::class)
        ->set('height', '10')
        ->call('update')
        ->assertHasErrors(['height']);
});

test('an existing home address is updated in place, not duplicated', function () {
    $user = makeStudentDetailsUser();
    $user->student->homeAddress()->create([
        'address1' => 'Old Address',
        'city' => 'Old City',
        'geo_state' => 'NY',
        'zip_code' => '10001',
    ]);

    Livewire::actingAs($user)
        ->test(StudentDetails::class)
        ->set('home_address1', 'New Address')
        ->set('home_city', 'New City')
        ->set('home_geo_state', 'nj')
        ->set('home_zip_code', '08901')
        ->call('update');

    expect(HomeAddress::where('student_id', $user->student->id)->count())->toBe(1);
    expect(HomeAddress::where('student_id', $user->student->id)->first()->address1)->toBe('New Address');
});
