<?php

declare(strict_types=1);

namespace App\Support\DirectorUpload;

/**
 * One normalized data row from a director upload CSV. Values are trimmed,
 * blank strings become null, zip/phone are digits-only, and enum-ish
 * columns are lower-cased. $errors holds row-level validation failures —
 * a row with errors is reported but never committed.
 *
 * Round-trips through toArray()/fromArray() so the Livewire upload
 * component can hold parsed rows between its preview and confirm requests.
 */
final readonly class DirectorUploadRow
{
    /**
     * @param  list<string>  $subjects
     * @param  list<string>  $errors
     */
    public function __construct(
        public int $line,
        public ?string $honorific,
        public ?string $firstName,
        public ?string $middleName,
        public ?string $lastName,
        public ?string $suffixName,
        public ?string $pronoun,
        public ?string $email,
        public ?string $cellPhone,
        public ?string $schoolName,
        public string $schoolType,
        public ?string $schoolCity,
        public ?string $schoolZip,
        public ?string $schoolCounty,
        public string $schoolState,
        public ?string $schoolEmail,
        public string $role,
        public array $subjects,
        public array $errors = [],
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function displayName(): string
    {
        return trim(($this->firstName ?? '').' '.($this->lastName ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }
}
