<?php

declare(strict_types=1);

namespace App\Livewire\Sfdi;

use App\Enums\ShirtSize;
use App\Models\Student;
use App\Models\VoicePart;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Biography's student-only fields (per studentfolder-module.md §4.1) — name,
 * pronoun, and email already live on the shared Settings\Profile page and
 * aren't duplicated here. Widget choices (height as a number input,
 * geo_state as a free 2-char input) deliberately match CandidateDetail's
 * existing teacher-side widgets rather than the source doc's literal
 * "select box" wording, so the same data reads/edits identically regardless
 * of which role is editing it.
 */
#[Layout('components.layouts.app')]
class StudentDetails extends Component
{
    public string $voice_part_id = '';

    public string $birthday = '';

    public string $shirt_size = '';

    public string $height = '';

    public string $home_address1 = '';

    public string $home_address2 = '';

    public string $home_city = '';

    public string $home_geo_state = '';

    public string $home_zip_code = '';

    public bool $saved = false;

    public function mount(): void
    {
        $student = $this->student();

        abort_if($student === null, 403);

        $this->voice_part_id = $student->voice_part_id !== null ? (string) $student->voice_part_id : '';
        $this->birthday = (string) $student->getRawOriginal('birthday');
        $this->shirt_size = (string) $student->getRawOriginal('shirt_size');
        $this->height = $student->height !== null ? (string) $student->height : '';

        $homeAddress = $student->homeAddress;
        $this->home_address1 = $homeAddress->address1 ?? '';
        $this->home_address2 = $homeAddress->address2 ?? '';
        $this->home_city = $homeAddress->city ?? '';
        $this->home_geo_state = $homeAddress->geo_state ?? '';
        $this->home_zip_code = $homeAddress->zip_code ?? '';
    }

    public function update(): void
    {
        $this->saved = false;

        $shirtSizeValues = array_map(fn (ShirtSize $size) => $size->value, ShirtSize::cases());

        $validated = $this->validate([
            'voice_part_id' => ['nullable', Rule::exists(VoicePart::class, 'id')],
            'birthday' => ['nullable', 'date'],
            'shirt_size' => ['required', Rule::in($shirtSizeValues)],
            'height' => ['nullable', 'integer', 'min:30', 'max:84'],
            'home_address1' => ['nullable', 'string', 'max:255'],
            'home_address2' => ['nullable', 'string', 'max:255'],
            'home_city' => ['nullable', 'string', 'max:255'],
            'home_geo_state' => ['nullable', 'string', 'max:2'],
            'home_zip_code' => ['nullable', 'string', 'max:10'],
        ]);

        $student = $this->student();

        $student->update([
            'voice_part_id' => $validated['voice_part_id'] !== '' ? (int) $validated['voice_part_id'] : null,
            'birthday' => $validated['birthday'] !== '' ? $validated['birthday'] : null,
            'shirt_size' => $validated['shirt_size'],
            'height' => $validated['height'] !== '' ? (int) $validated['height'] : null,
        ]);

        $homeAddressProvided = $this->home_address1 !== '' && $this->home_city !== ''
            && $this->home_geo_state !== '' && $this->home_zip_code !== '';

        if ($homeAddressProvided) {
            $student->homeAddress()->updateOrCreate([], [
                'address1' => $this->home_address1,
                'address2' => $this->home_address2 !== '' ? $this->home_address2 : null,
                'city' => $this->home_city,
                'geo_state' => mb_strtoupper($this->home_geo_state),
                'zip_code' => $this->home_zip_code,
            ]);
        }

        $this->saved = true;

        Flux::toast('Your details have been saved.');
    }

    public function render(): View
    {
        return view('livewire.sfdi.student-details', [
            'voiceParts' => VoicePart::ordered()->get(),
            'shirtSizeOptions' => ShirtSize::cases(),
            'grade' => $this->student()?->grade,
        ]);
    }

    private function student(): ?Student
    {
        return Auth::user()->student;
    }
}
