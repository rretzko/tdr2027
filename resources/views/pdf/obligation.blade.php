<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.partials.report-styles')
    <style>
        h1 { margin-bottom: 4px; }
        .subheading { margin-bottom: 24px; }
    </style>
</head>
<body>
    <h1>Teacher Obligations</h1>
    <p class="subheading">{{ $version->name }}</p>

    {!! $body !!}
</body>
</html>
