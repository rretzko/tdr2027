@php $logo ??= 'tdr-logo.svg'; @endphp
@if ($logo === 'tdr-logo.svg')
    {{-- .ico/apple-touch-icon are TDR-branded raster files with no SFDI
    equivalent yet — only emitted for the default TDR branding, since
    browsers were observed preferring the .ico over the SVG icon below
    when both are present, which would otherwise defeat a non-TDR $logo. --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
@endif
<link rel="icon" type="image/svg+xml" href="{{ asset('images/'.$logo) }}">
