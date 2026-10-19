<?php

declare(strict_types=1);

namespace App\Support\DirectorUpload;

use App\Enums\SchoolType;
use App\Enums\Subject;
use App\Enums\TeacherRole;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Parses and normalizes an Event Manager's director upload CSV
 * (resources/templates/director-upload-template.csv). Purely structural —
 * no database lookups; pronoun/state/county/school/person resolution is
 * DirectorUploadService's job.
 */
final class DirectorCsvParser
{
    public const MAX_ROWS = 500;

    /**
     * RFC 2606 reserved domain used by the template's example rows — rows
     * using it are skipped so an Event Manager who forgets to delete the
     * examples doesn't create "John Smith" accounts.
     */
    private const EXAMPLE_EMAIL_DOMAIN = '@example.com';

    /**
     * @var list<string>
     */
    public const REQUIRED_HEADERS = ['first_name', 'last_name', 'email', 'school_name', 'school_city', 'school_zip', 'school_county'];

    /**
     * @var list<string>
     */
    public const OPTIONAL_HEADERS = ['honorific', 'middle_name', 'suffix_name', 'pronoun', 'cell_phone', 'school_type', 'school_state', 'school_email', 'role', 'subjects'];

    public function parse(string $contents): DirectorCsvParseResult
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        // Excel's plain "CSV" save is Windows-1252, not UTF-8 (e.g. "Noël").
        // Unconverted, those bytes break Livewire's JSON state serialization.
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $header = fgetcsv($handle, escape: '');

        if ($header === false || $header === [null]) {
            fclose($handle);

            return new DirectorCsvParseResult([], ['The file is empty.'], []);
        }

        $header = array_map(fn (?string $h): string => mb_strtolower(trim((string) $h)), $header);

        $missing = array_values(array_diff(self::REQUIRED_HEADERS, $header));

        if ($missing !== []) {
            fclose($handle);

            return new DirectorCsvParseResult([], ['Missing required column(s): '.implode(', ', $missing).'.'], []);
        }

        $notices = [];
        $unknown = array_values(array_filter(
            array_diff($header, [...self::REQUIRED_HEADERS, ...self::OPTIONAL_HEADERS]),
            fn (string $h): bool => $h !== '',
        ));

        if ($unknown !== []) {
            $notices[] = 'Ignored unrecognized column(s): '.implode(', ', $unknown).'.';
        }

        $rows = [];
        $emailLines = [];
        $exampleRows = 0;
        $line = 1;

        while (($values = fgetcsv($handle, escape: '')) !== false) {
            $line++;

            if ($this->isBlank($values)) {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);

                return new DirectorCsvParseResult([], ['The file has more than '.self::MAX_ROWS.' rows. Split it into smaller files.'], []);
            }

            $raw = [];

            foreach ($header as $index => $name) {
                if ($name !== '') {
                    $raw[$name] = $this->clean($values[$index] ?? null);
                }
            }

            if ($raw['email'] !== null && str_ends_with(mb_strtolower($raw['email']), self::EXAMPLE_EMAIL_DOMAIN)) {
                $exampleRows++;

                continue;
            }

            $row = $this->buildRow($line, $raw);

            if ($row->email !== null) {
                $key = mb_strtolower($row->email);

                if (isset($emailLines[$key])) {
                    $row = new DirectorUploadRow(...[...$row->toArray(), 'errors' => [...$row->errors, "Duplicate email — already used on line {$emailLines[$key]}."]]);
                } else {
                    $emailLines[$key] = $line;
                }
            }

            $rows[] = $row;
        }

        fclose($handle);

        if ($exampleRows > 0) {
            $notices[] = "Skipped {$exampleRows} template example row(s) (@example.com).";
        }

        if ($rows === []) {
            return new DirectorCsvParseResult([], ['The file has no director rows.'], $notices);
        }

        return new DirectorCsvParseResult($rows, [], $notices);
    }

    /**
     * @param  array<string, ?string>  $raw
     */
    private function buildRow(int $line, array $raw): DirectorUploadRow
    {
        $schoolType = mb_strtolower($raw['school_type'] ?? '') ?: SchoolType::School->value;
        $role = mb_strtolower($raw['role'] ?? '') ?: TeacherRole::Primary->value;
        $subjects = ! isset($raw['subjects'])
            ? []
            : array_values(array_unique(array_filter(array_map(
                fn (string $s): string => mb_strtolower(trim($s)),
                preg_split('/[|;,]/', $raw['subjects']) ?: [],
            ))));

        $row = new DirectorUploadRow(
            line: $line,
            honorific: $raw['honorific'] ?? null,
            firstName: $raw['first_name'],
            middleName: $raw['middle_name'] ?? null,
            lastName: $raw['last_name'],
            suffixName: $raw['suffix_name'] ?? null,
            pronoun: $raw['pronoun'] ?? null,
            email: $raw['email'] !== null ? mb_strtolower($raw['email']) : null,
            cellPhone: PhoneNormalizer::normalize($raw['cell_phone'] ?? null),
            schoolName: $raw['school_name'],
            schoolType: $schoolType,
            schoolCity: $raw['school_city'],
            schoolZip: $this->normalizeZip($raw['school_zip']),
            schoolCounty: $raw['school_county'],
            schoolState: $raw['school_state'] ?? 'NJ',
            schoolEmail: isset($raw['school_email']) ? mb_strtolower($raw['school_email']) : null,
            role: $role,
            subjects: $subjects,
        );

        $validator = Validator::make([
            'honorific' => $row->honorific,
            'first_name' => $row->firstName,
            'middle_name' => $row->middleName,
            'last_name' => $row->lastName,
            'suffix_name' => $row->suffixName,
            'email' => $row->email,
            'cell_phone' => $row->cellPhone,
            'school_name' => $row->schoolName,
            'school_type' => $row->schoolType,
            'school_city' => $row->schoolCity,
            'school_zip' => $row->schoolZip,
            'school_county' => $row->schoolCounty,
            'school_email' => $row->schoolEmail,
            'role' => $row->role,
            'subjects' => $row->subjects,
        ], [
            'honorific' => ['nullable', 'string', 'max:50'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'suffix_name' => ['nullable', 'string', 'max:50'],
            'email' => ['required', 'string', 'email:rfc,filter', 'max:255'],
            'cell_phone' => ['nullable', 'string', 'min:10', 'max:20'],
            'school_name' => ['required', 'string', 'max:255'],
            'school_type' => ['required', Rule::in(array_map(fn (SchoolType $t): string => $t->value, SchoolType::cases()))],
            'school_city' => ['required', 'string', 'max:255'],
            'school_zip' => ['required', 'regex:/^\d{5}$/'],
            'school_county' => ['required', 'string', 'max:255'],
            'school_email' => ['nullable', 'string', 'email:rfc,filter', 'max:255'],
            'role' => ['required', Rule::in(array_map(fn (TeacherRole $r): string => $r->value, TeacherRole::cases()))],
            'subjects' => ['array'],
            'subjects.*' => [Rule::in(array_map(fn (Subject $s): string => $s->value, Subject::cases()))],
        ], [
            'cell_phone.min' => 'The cell phone must have at least 10 digits.',
            'school_zip.regex' => 'The school zip must be 5 digits.',
            'subjects.*.in' => 'Subjects must be band, chorus and/or orchestra.',
            'role.in' => 'The role must be primary or coteacher.',
            'school_type.in' => 'The school type must be school or studio.',
        ]);

        if ($validator->fails()) {
            return new DirectorUploadRow(...[...$row->toArray(), 'errors' => array_values(array_unique($validator->errors()->all()))]);
        }

        return $row;
    }

    /**
     * Restores leading zeros Excel strips from NJ zips ("8608" → "08608")
     * and trims ZIP+4 to its 5-digit prefix.
     */
    private function normalizeZip(?string $zip): ?string
    {
        if ($zip === null) {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', $zip);

        if (strlen($digits) === 9) {
            return substr($digits, 0, 5);
        }

        if (strlen($digits) >= 3 && strlen($digits) < 5) {
            return str_pad($digits, 5, '0', STR_PAD_LEFT);
        }

        return $digits === '' ? null : $digits;
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<int, ?string>  $values
     */
    private function isBlank(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
