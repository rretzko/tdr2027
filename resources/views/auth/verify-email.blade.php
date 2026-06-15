<x-layouts.auth title="Verify email">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-2 text-center">
            <flux:heading size="xl">Verify your email</flux:heading>
            <flux:subheading>
                Thanks for signing up! Before getting started, please verify your email address by clicking the link
                we just emailed to you.
            </flux:subheading>
        </div>

        @if (session('status') == 'verification-link-sent')
            <flux:callout color="green" icon="check-circle" heading="Verification link sent">
                <flux:callout.text>
                    A new verification link has been sent to the email address you provided during registration.
                </flux:callout.text>
            </flux:callout>
        @endif

        <form method="POST" action="{{ route('verification.send') }}" class="flex flex-col gap-4">
            @csrf
            <flux:button type="submit" variant="primary">
                Resend verification email
            </flux:button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <flux:button type="submit" variant="ghost" class="w-full">
                Log out
            </flux:button>
        </form>
    </div>
</x-layouts.auth>
