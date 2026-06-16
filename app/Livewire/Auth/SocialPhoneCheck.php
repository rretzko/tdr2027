<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\SocialAccount;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.auth')]
class SocialPhoneCheck extends Component
{
    public string $cell_phone = '';

    public function save(): void
    {
        $payload = session('social_oauth_payload');

        if ($payload === null) {
            $this->redirect(route('login'), navigate: true);

            return;
        }

        $this->validate([
            'cell_phone' => ['required', 'string', 'min:10', 'max:20'],
        ]);

        $phone = preg_replace('/\D/', '', $this->cell_phone);

        $existingUser = User::where('cell_phone', $phone)->first();

        DB::transaction(function () use ($phone, $payload, $existingUser) {
            if ($existingUser !== null) {
                SocialAccount::firstOrCreate(
                    [
                        'provider'         => $payload['provider'],
                        'provider_user_id' => $payload['provider_user_id'],
                    ],
                    [
                        'user_id'                => $existingUser->id,
                        'provider_token'         => $payload['token'],
                        'provider_refresh_token' => $payload['refresh_token'],
                        'provider_avatar'        => $payload['avatar'],
                    ],
                );

                Auth::login($existingUser, remember: true);
                session()->regenerate();
            } else {
                $user = $this->registerSocialTeacher($phone, $payload);

                Auth::login($user, remember: true);
                session()->regenerate();
            }
        });

        session()->forget('social_oauth_payload');

        if ($existingUser !== null) {
            $this->redirect(route('dashboard'), navigate: true);
        } else {
            $this->redirect(route('social.profile.complete'), navigate: true);
        }
    }

    /** @param array<string, mixed> $payload */
    private function registerSocialTeacher(string $phone, array $payload): User
    {
        $nameParts = $this->parseName((string) ($payload['name'] ?? ''));

        $user = User::create([
            'first_name' => $nameParts['first_name'],
            'last_name'  => $nameParts['last_name'],
            'email'      => $payload['email'],
            'password'   => null,
            'pronoun_id' => null,
            'cell_phone' => $phone,
        ]);

        if ($payload['email'] !== null) {
            $user->markEmailAsVerified();
        }

        Teacher::create(['user_id' => $user->id]);
        $user->assignRole('Teacher');

        SocialAccount::create([
            'user_id'                => $user->id,
            'provider'               => $payload['provider'],
            'provider_user_id'       => $payload['provider_user_id'],
            'provider_token'         => $payload['token'],
            'provider_refresh_token' => $payload['refresh_token'],
            'provider_avatar'        => $payload['avatar'],
        ]);

        return $user;
    }

    /** @return array{first_name: string, last_name: string} */
    private function parseName(string $fullName): array
    {
        $parts = array_values(array_filter(explode(' ', trim($fullName))));

        if (count($parts) === 0) {
            return ['first_name' => 'Unknown', 'last_name' => 'Unknown'];
        }

        $last  = array_pop($parts);
        $first = array_shift($parts) ?? $last;

        return ['first_name' => $first, 'last_name' => $last];
    }

    public function render(): View
    {
        return view('livewire.auth.social-phone-check');
    }
}
