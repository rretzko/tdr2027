<div class="flex flex-col gap-6 lg:flex-row">
    <div class="lg:w-48">
        <flux:navlist>
            <flux:navlist.item :href="route('settings.profile')" :current="request()->routeIs('settings.profile')">
                Profile
            </flux:navlist.item>
            <flux:navlist.item :href="route('settings.password')" :current="request()->routeIs('settings.password')">
                Password
            </flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="lg:hidden" />

    <div class="flex-1 max-w-xl">
        {{ $slot }}
    </div>
</div>
