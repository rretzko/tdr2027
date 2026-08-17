<?php

declare(strict_types=1);

use App\Livewire\Sfdi\EmergencyContacts;
use App\Models\EmergencyContact;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeEmergencyContactsUser(): User
{
    $user = User::factory()->create();
    Student::factory()->create(['user_id' => $user->id]);

    return $user;
}

test('a teacher without a student profile is forbidden', function () {
    $user = User::factory()->create();
    Teacher::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)->test(EmergencyContacts::class)->assertForbidden();
});

test('a student can add an emergency contact', function () {
    $user = makeEmergencyContactsUser();

    Livewire::actingAs($user)
        ->test(EmergencyContacts::class)
        ->call('addEmergencyContact')
        ->set('ec_name', 'Jane Doe')
        ->set('ec_relationship', 'mother')
        ->set('ec_cell_phone', '2015551234')
        ->call('save')
        ->assertHasNoErrors();

    expect(EmergencyContact::where('student_id', $user->student->id)->where('name', 'Jane Doe')->exists())->toBeTrue();
});

test('name and relationship are required', function () {
    $user = makeEmergencyContactsUser();

    Livewire::actingAs($user)
        ->test(EmergencyContacts::class)
        ->call('addEmergencyContact')
        ->call('save')
        ->assertHasErrors(['ec_name', 'ec_relationship']);
});

test('a student can edit their own emergency contact', function () {
    $user = makeEmergencyContactsUser();
    $ec = EmergencyContact::create([
        'student_id' => $user->student->id,
        'name' => 'Old Name',
        'relationship' => 'mother',
    ]);

    Livewire::actingAs($user)
        ->test(EmergencyContacts::class)
        ->call('editEmergencyContact', $ec->id)
        ->set('ec_name', 'New Name')
        ->call('save')
        ->assertHasNoErrors();

    expect($ec->fresh()->name)->toBe('New Name');
});

test('a student cannot edit another student\'s emergency contact', function () {
    $user = makeEmergencyContactsUser();
    $otherStudent = Student::factory()->create();
    $ec = EmergencyContact::create([
        'student_id' => $otherStudent->id,
        'name' => 'Not Mine',
        'relationship' => 'mother',
    ]);

    Livewire::actingAs($user)
        ->test(EmergencyContacts::class)
        ->call('editEmergencyContact', $ec->id)
        ->assertNotFound();
});

test('a student can remove their own emergency contact', function () {
    $user = makeEmergencyContactsUser();
    $ec = EmergencyContact::create([
        'student_id' => $user->student->id,
        'name' => 'Removable',
        'relationship' => 'other',
    ]);

    Livewire::actingAs($user)
        ->test(EmergencyContacts::class)
        ->call('remove', $ec->id);

    expect(EmergencyContact::find($ec->id))->toBeNull();
});
