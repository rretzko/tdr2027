@props(['action'])

{{--
    Cloudflare Turnstile widget for a Livewire form whose component uses
    GuardsAgainstBotRegistration. Rendered explicitly (the host view loads
    api.js?render=explicit via @assets) so wire:ignore keeps Livewire's DOM
    morph from wiping the iframe. Tokens are single-use, so the server
    dispatches turnstile-reset after every verification attempt.
--}}
<div>
    <div
        wire:ignore
        x-data="{ widgetId: null }"
        x-init="
            const render = () => {
                widgetId = window.turnstile.render($refs.box, {
                    sitekey: @js((string) config('services.turnstile.site_key')),
                    action: @js($action),
                    callback: (token) => $wire.$set('turnstileToken', token, false),
                    'expired-callback': () => $wire.$set('turnstileToken', '', false),
                    'error-callback': () => $wire.$set('turnstileToken', '', false),
                });
            };
            const wait = setInterval(() => {
                if (window.turnstile) {
                    clearInterval(wait);
                    render();
                }
            }, 100);
        "
        x-on:turnstile-reset.window="if (widgetId !== null) { window.turnstile.reset(widgetId); }"
    >
        <div x-ref="box"></div>
    </div>
    <flux:error name="turnstile" />
</div>
