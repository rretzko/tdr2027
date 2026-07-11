<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page {
            margin: 24px;
        }

        body {
            font-family: sans-serif;
        }
    </style>
</head>
<body>
    @include('candidate-application.document', [
        'version' => $version,
        'data' => $data,
        'studentBody' => $studentBody,
        'parentBody' => $parentBody,
        'teacherBody' => $teacherBody,
        'showTeacherSection' => $showTeacherSection,
    ])
</body>
</html>
