@php
    $shortName = $version->short_name ?? $version->name;
@endphp
<div class="estimate-header">
    @if ($data->organizationLogoUrl !== null)
        <img src="{{ $data->organizationLogoUrl }}" alt="{{ $data->organizationLogoAlt ?? 'Organization logo' }}">
    @endif

    <p class="version-name">{{ $version->name }}</p>
    <p class="version-subtitle">{{ $shortName }} Estimate Form</p>
    <p class="meta-line">{{ $data->teacher->user->name }} &mdash; {{ $data->school->name }}</p>
    <p class="meta-line">Downloaded on: {{ $data->generatedAt }}</p>

    @if ($version->max_registrants !== null && $version->max_registrants > 0)
        <p class="max-line">{{ $version->max_registrants }} STUDENTS MAXIMUM</p>
    @endif
</div>
