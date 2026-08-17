<?php

declare(strict_types=1);

use App\Models\Pivots\SchoolStudent;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\PortalBranding;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

function tdrTitleTag(): string
{
    return '<title>'.e(PortalBranding::appName()).'</title>';
}

function sfdiTitleTag(): string
{
    return '<title>'.e(PortalBranding::appName(sfdi: true)).'</title>';
}

test('the TDR welcome page uses the TDR logo, favicon, and title', function () {
    get('/')
        ->assertOk()
        ->assertSee('images/tdr-logo.svg')
        ->assertSee('favicon.ico')
        ->assertSee(tdrTitleTag(), false)
        ->assertDontSee('images/sfdi-logo.svg');
});

test('the SFDI welcome page uses only the SFDI SVG favicon and SF title, not TDR\'s', function () {
    get(route('sfdi.welcome'))
        ->assertOk()
        ->assertSee('images/sfdi-logo.svg')
        ->assertSee(sfdiTitleTag(), false)
        ->assertDontSee('images/tdr-logo.svg')
        ->assertDontSee('favicon.ico')
        ->assertDontSee('apple-touch-icon.png');
});

test('the SFDI login page uses only the SFDI SVG favicon and SF title, not TDR\'s', function () {
    get(route('sfdi.login'))
        ->assertOk()
        ->assertSee('images/sfdi-logo.svg')
        ->assertSee(sfdiTitleTag(), false)
        ->assertDontSee('images/tdr-logo.svg')
        ->assertDontSee('favicon.ico')
        ->assertDontSee('apple-touch-icon.png');
});

test('the SFDI register page uses only the SFDI SVG favicon and SF title, not TDR\'s', function () {
    get(route('sfdi.register'))
        ->assertOk()
        ->assertSee('images/sfdi-logo.svg')
        ->assertSee(sfdiTitleTag(), false)
        ->assertDontSee('images/tdr-logo.svg')
        ->assertDontSee('favicon.ico')
        ->assertDontSee('apple-touch-icon.png');
});

test('a logged-in student sees the SFDI favicon, title, and sidebar brand on every authenticated page, not just the /sfdi/ routes', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $student = Student::factory()->create(['user_id' => $user->id]);
    $school = School::factory()->create();
    SchoolStudent::create(['student_id' => $student->id, 'school_id' => $school->id, 'is_active' => true, 'class_of' => 2030]);

    foreach ([route('dashboard'), route('sfdi.school'), route('sfdi.student-details'), route('sfdi.emergency-contacts')] as $url) {
        actingAs($user)->get($url)
            ->assertOk()
            ->assertSee('<link rel="icon" type="image/svg+xml" href="'.asset('images/sfdi-logo.svg').'">', false)
            ->assertSee(sfdiTitleTag(), false)
            // The sidebar brand <img> must be explicitly sized — Flux's
            // sidebar.brand slot wrapper constrains its own <div> to h-6
            // w-6 with overflow-hidden, but does NOT size the <img> inside
            // it, so an unsized <img> renders at its native SVG dimensions
            // (640x640) and gets clipped to an arbitrary corner instead of
            // scaling down.
            ->assertSee('<img src="'.asset('images/sfdi-logo.svg').'" alt="" class="h-6 w-6 object-contain">', false)
            ->assertDontSee('favicon.ico')
            ->assertDontSee('images/tdr-logo.svg');
    }
});

test('a logged-in teacher still sees the TDR favicon, title, and sidebar brand on the dashboard (no regression)', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('images/tdr-logo.svg')
        ->assertSee('favicon.ico')
        ->assertSee(tdrTitleTag(), false)
        ->assertDontSee('images/sfdi-logo.svg');
});
