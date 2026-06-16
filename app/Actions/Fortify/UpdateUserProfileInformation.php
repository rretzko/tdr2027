<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\Pronoun;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'honorific'   => ['nullable', 'string', 'max:50'],
            'first_name'  => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name'   => ['required', 'string', 'max:255'],
            'suffix_name' => ['nullable', 'string', 'max:50'],
            'pronoun_id'  => ['required', 'integer', Rule::exists(Pronoun::class, 'id')],
            'email'       => ['required', 'string', 'email:rfc,filter', 'max:255', Rule::unique('users')->ignore($user->id)],
            'cell_phone'  => ['required', 'string', 'min:10', 'max:20', Rule::unique('users')->ignore($user->id)],
        ])->validateWithBag('updateProfileInformation');

        $emailChanged = $user->email !== $input['email'];

        $user->forceFill([
            'honorific'   => $input['honorific'] ?? null,
            'first_name'  => $input['first_name'],
            'middle_name' => $input['middle_name'] ?? null,
            'last_name'   => $input['last_name'],
            'suffix_name' => $input['suffix_name'] ?? null,
            'pronoun_id'  => $input['pronoun_id'] ?: null,
            'email'       => $input['email'],
            'cell_phone'  => preg_replace('/\D/', '', (string) $input['cell_phone']),
        ])->save();

        if ($emailChanged) {
            $user->forceFill(['email_verified_at' => null])->save();
            $user->sendEmailVerificationNotification();
        }
    }
}
