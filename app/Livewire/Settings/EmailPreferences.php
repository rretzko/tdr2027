<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Enums\UnsubscribableEmail;
use App\Models\EmailUnsubscribe;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Opt in/out of the scheduled digest emails. The same email_unsubscribes rows
 * the emails' signed unsubscribe links write, so this is also where a user
 * resubscribes after clicking one.
 */
#[Layout('components.layouts.app')]
class EmailPreferences extends Component
{
    /** @var array<string, bool> subscribed state keyed by UnsubscribableEmail value */
    public array $subscribed = [];

    public function mount(): void
    {
        $unsubscribed = EmailUnsubscribe::where('user_id', Auth::id())->pluck('email_type')
            ->map(fn (UnsubscribableEmail $email): string => $email->value)
            ->all();

        foreach (UnsubscribableEmail::cases() as $email) {
            $this->subscribed[$email->value] = ! in_array($email->value, $unsubscribed, true);
        }
    }

    public function updatedSubscribed(bool $value, string $key): void
    {
        $email = UnsubscribableEmail::from($key);

        if ($value) {
            EmailUnsubscribe::where('user_id', Auth::id())->where('email_type', $email->value)->delete();
        } else {
            EmailUnsubscribe::firstOrCreate(['user_id' => Auth::id(), 'email_type' => $email->value]);
        }

        Flux::toast(
            text: $value ? "You'll receive the {$email->label()}." : "You won't receive the {$email->label()}.",
            variant: 'success',
        );
    }
}
