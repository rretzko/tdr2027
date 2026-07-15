@props(['tokens' => [], 'target'])

<flux:dropdown position="bottom" align="start">
    <flux:button size="sm" variant="ghost" icon="code-bracket">Insert token</flux:button>

    <flux:menu>
        @foreach ($tokens as $token => $description)
            @php
                $mergeToken = '{' . '{' . $token . '}' . '}';
                $insertJs = "\$refs.{$target}.editor.chain().focus().insertContent('{$mergeToken}').run()";
            @endphp
            <flux:menu.item suffix="{{ $description }}" x-on:click="{{ $insertJs }}">
                <code class="font-mono text-xs">{{ $mergeToken }}</code>
            </flux:menu.item>
        @endforeach
    </flux:menu>
</flux:dropdown>
