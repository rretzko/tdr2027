<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\Pronoun;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.layouts.app')]
class Profile extends Component
{
    use WithFileUploads;

    public string $honorific = '';

    public string $first_name = '';

    public string $middle_name = '';

    public string $last_name = '';

    public string $suffix_name = '';

    public string $pronoun_id = '';

    public string $email = '';

    public string $cell_phone = '';

    public bool $saved = false;

    public $photo = null;

    public function mount(): void
    {
        $user = auth()->user();

        $this->honorific = (string) $user->honorific;
        $this->first_name = $user->first_name;
        $this->middle_name = (string) $user->middle_name;
        $this->last_name = $user->last_name;
        $this->suffix_name = (string) $user->suffix_name;
        $this->pronoun_id = $user->pronoun_id !== null ? (string) $user->pronoun_id : '';
        $this->email = (string) $user->email;
        $this->cell_phone = (string) $user->cell_phone;
    }

    /**
     * Auto-saves on selection rather than waiting for the form's own Save
     * button — every other single-purpose action on this page (uploads,
     * toggles) elsewhere in the app is immediate, and a photo preview that
     * silently discards itself if the user forgets to hit Save would be a
     * worse experience than the inconsistency with the name/email fields.
     */
    public function updatedPhoto(): void
    {
        $this->validate([
            'photo' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $user = Auth::user();
        $previousPath = $user->photo_path;

        $path = $this->photo->store('thumbnails', 's3');
        $user->update(['photo_path' => $path]);

        // Deleted after the new one is committed, not before — a failed
        // upload above never reaches here, so the old photo is never lost
        // to a failed replacement.
        if ($previousPath !== null) {
            Storage::disk('s3')->delete($previousPath);
        }

        $this->photo = null;

        Flux::toast('Profile photo updated.');
    }

    public function removePhoto(): void
    {
        $user = Auth::user();

        if ($user->photo_path !== null) {
            Storage::disk('s3')->delete($user->photo_path);
            $user->update(['photo_path' => null]);
        }

        Flux::toast('Profile photo removed.');
    }

    public function update(): void
    {
        $this->saved = false;

        $user = auth()->user();

        app(UpdatesUserProfileInformation::class)->update($user, $this->only([
            'honorific', 'first_name', 'middle_name', 'last_name', 'suffix_name',
            'pronoun_id', 'email', 'cell_phone',
        ]));

        $this->saved = true;
    }

    public function render(): View
    {
        return view('livewire.settings.profile', [
            'pronouns' => Pronoun::orderBy('sort_order')->get(),
        ]);
    }
}
