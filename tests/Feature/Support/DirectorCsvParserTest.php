<?php

declare(strict_types=1);

use App\Support\DirectorUpload\DirectorCsvParser;

test('the shipped template parses with both example rows skipped', function () {
    $result = (new DirectorCsvParser)->parse((string) file_get_contents(resource_path('templates/director-upload-template.csv')));

    expect($result->fileErrors)->toBe(['The file has no director rows.'])
        ->and($result->notices)->toContain('Skipped 2 template example row(s) (@example.com).');
});

test('missing required headers reject the whole file', function () {
    $result = (new DirectorCsvParser)->parse("first_name,last_name,email\nA,B,a@b.org\n");

    expect($result->rows)->toBe([])
        ->and($result->fileErrors[0])->toContain('school_name')
        ->and($result->fileErrors[0])->toContain('school_county');
});

test('rows are normalized: BOM, header case, zip padding, phone digits, subjects, defaults', function () {
    $csv = "\xEF\xBB\xBFFirst_Name, Last_Name ,EMAIL,school_name,school_city,school_zip,school_county,cell_phone,subjects,extra\n"
        ."Ann,Lee,Ann.Lee@Test.ORG,Some HS,Town,8608,Mercer,(609) 555-0101,Band; Chorus,x\n"
        .",,,,,,,,,\n";

    $result = (new DirectorCsvParser)->parse($csv);
    $row = $result->rows[0];

    expect($result->rows)->toHaveCount(1)
        ->and($result->notices)->toContain('Ignored unrecognized column(s): extra.')
        ->and($row->line)->toBe(2)
        ->and($row->email)->toBe('ann.lee@test.org')
        ->and($row->schoolZip)->toBe('08608')
        ->and($row->cellPhone)->toBe('6095550101')
        ->and($row->subjects)->toBe(['band', 'chorus'])
        ->and($row->schoolType)->toBe('school')
        ->and($row->role)->toBe('primary')
        ->and($row->schoolState)->toBe('NJ')
        ->and($row->hasErrors())->toBeFalse();
});

test('invalid values become row errors rather than rejecting the file', function () {
    $csv = "first_name,last_name,email,school_name,school_city,school_zip,school_county,role,subjects\n"
        .",Lee,not-an-email,Some HS,Town,12,Mercer,boss,tuba\n";

    $row = (new DirectorCsvParser)->parse($csv)->rows[0];

    expect($row->hasErrors())->toBeTrue()
        ->and(implode(' ', $row->errors))->toContain('first name')
        ->and(implode(' ', $row->errors))->toContain('zip')
        ->and(implode(' ', $row->errors))->toContain('primary or coteacher')
        ->and(implode(' ', $row->errors))->toContain('band, chorus');
});

test('more than the maximum rows is rejected', function () {
    $csv = "first_name,last_name,email,school_name,school_city,school_zip,school_county\n";

    for ($i = 0; $i <= DirectorCsvParser::MAX_ROWS; $i++) {
        $csv .= "A,B,a{$i}@test.org,S,T,08608,Mercer\n";
    }

    expect((new DirectorCsvParser)->parse($csv)->fileErrors[0])->toContain('more than');
});
