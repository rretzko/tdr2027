@if ($row['errors'] !== [] || $row['warnings'] !== [] || $row['notes'] !== [])
    <ul class="mt-1 space-y-0.5 text-sm">
        @foreach ($row['errors'] as $message)
            <li class="text-red-600 dark:text-red-400">{{ $message }}</li>
        @endforeach
        @foreach ($row['warnings'] as $message)
            <li class="text-amber-600 dark:text-amber-400">{{ $message }}</li>
        @endforeach
        @foreach ($row['notes'] as $message)
            <li class="text-zinc-500 dark:text-zinc-400">{{ $message }}</li>
        @endforeach
    </ul>
@endif
