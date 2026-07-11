{{--
    Shared, mode-agnostic Candidate Application document. Included by both the
    dompdf entry view (resources/views/pdf/candidate-application.blade.php,
    real candidate data) and the admin authoring Preview modal
    (VersionEdit's application tab, placeholder data) — single source of
    truth so the two never drift. $teacherBody is null and $showTeacherSection
    is false for EApplication-mode Versions (no Teacher/Principal section at all).

    @var \App\Models\Version $version
    @var \App\Support\CandidateApplicationData $data
    @var string $studentBody
    @var string $parentBody
    @var string|null $teacherBody
    @var bool $showTeacherSection
--}}
<div class="mt-4" style="font-family: sans-serif;">
    <style>
        .ca-sectionHeader {
            background-color: lightblue;
            text-transform: uppercase;
            padding: 0 0.25rem;
            font-size: 1rem;
            font-weight: bold;
            margin-bottom: 0.25rem;
        }

        .ca-summaryTbl {
            border-collapse: collapse;
            width: 99%;
            margin: auto;
            margin-bottom: 0.5rem;
        }

        .ca-summaryTbl td, .ca-summaryTbl th {
            border: 1px solid black;
            text-align: center;
            padding: 0.25rem;
        }

        .ca-conditions {
            font-size: 0.85rem;
        }
    </style>

    {{-- HEADER --}}
    <table style="border-collapse: collapse; width: 99%; margin: auto; margin-bottom: 0.5rem;">
        <tbody>
            <tr>
                <td>
                    @if ($data->organizationLogoUrl)
                        <img src="{{ $data->organizationLogoUrl }}" alt="{{ $data->organizationLogoAlt ?? $data->organizationName }}" height="60" width="60" />
                    @endif
                </td>
                <td style="text-align: right; font-weight: bold;">
                    <div>{{ $data->organizationName }}</div>
                    <div>{{ $version->name }}</div>
                </td>
            </tr>
        </tbody>
    </table>

    {{-- CANDIDATE SUMMARY --}}
    <table class="ca-summaryTbl">
        <tbody>
            <tr>
                <td>{{ $data->candidateFullName }}</td>
                <td style="font-size: 20px; font-weight: bold;">{{ $data->voicePartName }}</td>
                <td>Grade: {{ $data->grade }}</td>
                <td>{{ $data->schoolShortName }}</td>
            </tr>
        </tbody>
    </table>

    <table class="ca-summaryTbl">
        <tbody>
            <tr style="background-color: lightgray;">
                <th>Student Cell Phone</th>
                <th>Emergency Contact</th>
                <th>Emergency Contact Phone</th>
            </tr>
            <tr>
                <td>{{ $data->studentCellPhone }}</td>
                <td>{{ $data->emergencyContactName }}</td>
                <td>{{ $data->emergencyContactPhone }}</td>
            </tr>
        </tbody>
    </table>

    {{-- FEES --}}
    <section style="text-align: right; font-weight: bold; margin-bottom: 0.5rem;">
        <div>Registration Fee: ${{ $data->registrationFee }} &nbsp; Participation Fee: ${{ $data->participationFee }}</div>
    </section>

    {{-- STUDENT ENDORSEMENT --}}
    <section style="margin-bottom: 1rem;">
        <header class="ca-sectionHeader">Student Endorsement — Signature Required</header>
        <div class="ca-conditions">{!! $studentBody !!}</div>
        <table style="width: 100%; margin-top: 0.5rem;">
            <tr>
                <td style="text-align: left;">{{ $data->candidateFullName }} Signature: ________________________</td>
                <td style="text-align: right;">Date: _________</td>
            </tr>
        </table>
    </section>

    {{-- PARENT/GUARDIAN ENDORSEMENT --}}
    <section style="margin-bottom: 1rem;">
        <header class="ca-sectionHeader">Parent/Guardian Endorsement — Signature Required</header>
        <div class="ca-conditions">{!! $parentBody !!}</div>
        <table style="width: 100%; margin-top: 0.5rem;">
            <tr>
                <td style="text-align: left;">Signature of {{ $data->emergencyContactName }}: ________________________</td>
                <td style="text-align: right;">Date: _________</td>
            </tr>
        </table>
    </section>

    {{-- TEACHER/PRINCIPAL ENDORSEMENT (Pdf mode only) --}}
    @if ($showTeacherSection)
        <section style="margin-bottom: 1rem;">
            <header class="ca-sectionHeader">Teacher/Principal Endorsement — Signatures Required</header>
            <div class="ca-conditions">{!! $teacherBody !!}</div>
            <table style="width: 100%; margin-top: 0.5rem;">
                <tr>
                    <td style="text-align: left;">{{ $data->teacherFullName }} Signature: ________________________</td>
                    <td style="text-align: center;">Principal Signature: ________________________</td>
                    <td style="text-align: right;">Date: _________</td>
                </tr>
            </table>
        </section>
    @endif
</div>
