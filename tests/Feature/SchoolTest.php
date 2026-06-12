<?php

declare(strict_types=1);

use App\Models\County;
use App\Models\Geostate;
use App\Models\School;
use App\Models\SchoolGrade;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

test('senior_year is the current year when "now" is January through June', function () {
    Carbon::setTestNow(Carbon::create(2026, 3, 15));

    $school = School::factory()->create(['school_year' => 'US']);

    expect($school->senior_year)->toBe(2026);
});

test('senior_year is next year when "now" is July through December', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 15));

    $school = School::factory()->create(['school_year' => 'US']);

    expect($school->senior_year)->toBe(2027);
});

test('school belongs to a county and optionally a geostate', function () {
    $geostate = Geostate::factory()->create();
    $county = County::factory()->create(['geostate_id' => $geostate->id]);

    $school = School::factory()->create([
        'geostate_id' => $geostate->id,
        'county_id' => $county->id,
    ]);

    expect($school->county->id)->toBe($county->id);
    expect($school->geostate->id)->toBe($geostate->id);
});

test('geostate_id is nullable', function () {
    $school = School::factory()->create(['geostate_id' => null]);

    expect($school->geostate)->toBeNull();
});

test('getGradesAttribute returns ordered grade numbers', function () {
    $school = School::factory()->create();

    SchoolGrade::factory()->create(['school_id' => $school->id, 'grade' => 9]);
    SchoolGrade::factory()->create(['school_id' => $school->id, 'grade' => 7]);
    SchoolGrade::factory()->create(['school_id' => $school->id, 'grade' => 8]);

    expect($school->grades)->toBe([7, 8, 9]);
});

test('getShortNameAttribute abbreviates common school name suffixes', function (string $input, string $expected) {
    $school = School::factory()->create([
        'name' => $input,
        'zip_code' => fake()->unique()->numerify('#####'),
    ]);

    expect($school->short_name)->toBe($expected);
})->with([
    ['Lincoln High School', 'Lincoln HS'],
    ['Lincoln Middle School', 'Lincoln MS'],
    ['Lincoln Elementary School', 'Lincoln ES'],
    ['Lincoln Regional High School', 'Lincoln RHS'],
    ['Lincoln Regional Middle School', 'Lincoln RMS'],
    ['Lincoln Senior High School', 'Lincoln Sr HS'],
    ['Lincoln Junior/Senior High School', 'Lincoln Junior/Sr HS'],
    ['Lincoln Junior/senior High School', 'Lincoln J/S HS'],
]);

test('school name + zip_code must be unique', function () {
    School::factory()->create(['name' => 'Lincoln High School', 'zip_code' => '12345']);

    expect(fn () => School::factory()->create(['name' => 'Lincoln High School', 'zip_code' => '12345']))
        ->toThrow(QueryException::class);
});
