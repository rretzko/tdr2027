<?php

declare(strict_types=1);

use App\Models\Version;
use App\Support\CandidateApplicationData;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('Schedule and Policies sections render when their bodies are present', function () {
    $version = Version::factory()->create();
    $data = CandidateApplicationData::placeholder($version);

    $html = view('candidate-application.document', [
        'version' => $version,
        'data' => $data,
        'studentBody' => '<p>Student text.</p>',
        'parentBody' => '<p>Parent text.</p>',
        'teacherBody' => null,
        'scheduleBody' => '<p>Rehearsal on Friday at 6pm.</p>',
        'policiesBody' => '<p>No late arrivals permitted.</p>',
        'showTeacherSection' => false,
    ])->render();

    expect($html)
        ->toContain('Schedule')
        ->toContain('Rehearsal on Friday at 6pm.')
        ->toContain('Policies')
        ->toContain('No late arrivals permitted.');
});

test('Schedule and Policies sections are omitted when their bodies are null or blank', function () {
    $version = Version::factory()->create();
    $data = CandidateApplicationData::placeholder($version);

    $html = view('candidate-application.document', [
        'version' => $version,
        'data' => $data,
        'studentBody' => '<p>Student text.</p>',
        'parentBody' => '<p>Parent text.</p>',
        'teacherBody' => null,
        'scheduleBody' => null,
        'policiesBody' => '<p></p>',
        'showTeacherSection' => false,
    ])->render();

    expect($html)->not->toContain('>Schedule<');
    expect($html)->not->toContain('>Policies<');
});
