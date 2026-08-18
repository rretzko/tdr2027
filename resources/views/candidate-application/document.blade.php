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
    @var string|null $scheduleBody
    @var string|null $policiesBody
    @var bool $showTeacherSection
    @var \Illuminate\Support\Carbon|null $candidateSignedAt  -- EApplication mode only; omitted/null renders a blank signature line
    @var \Illuminate\Support\Carbon|null $parentSignedAt     -- EApplication mode only; omitted/null renders a blank signature line
--}}
{{--
    Explicit white background + black text on the root element below —
    this represents an actual paper/PDF document (dompdf renders it
    identically), so it must always look like paper regardless of the
    app's light/dark theme, not adapt to it. Without this, the in-app View
    modal (unlike the PDF, which has no theme at all) inherited the app's
    dark-mode text color while the section-header/table backgrounds below
    stayed hardcoded light (lightblue/lightgray), which made that text
    unreadable in dark mode (found 2026-08-18).
--}}
<div class="mt-4" style="font-family: sans-serif; background-color: #ffffff; color: #000000; padding: 1rem; border-radius: 0.375rem;">
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
            word-wrap: break-word;
            overflow-wrap: anywhere;
        }

        .ca-conditions p {
            margin: 0;
        }

        .ca-conditions ul, .ca-conditions ol {
            margin: 0.25rem 0;
        }

        .ca-conditions li {
            margin: 0;
        }

        .ca-conditions li p {
            display: inline;
        }

        .ca-signature {
            font-family: 'Brush Script MT', cursive;
            font-style: italic;
            font-size: 1.25rem;
            margin-left: 0.5rem;
        }

        .ca-signature-note {
            font-size: 0.7rem;
            color: #555;
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

    {{-- SCHEDULE (optional) --}}
    @if ($scheduleBody !== null && trim(strip_tags($scheduleBody)) !== '')
        <section style="margin-bottom: 1rem;">
            <header class="ca-sectionHeader">Schedule</header>
            <div class="ca-conditions">{!! $scheduleBody !!}</div>
        </section>
    @endif

    {{-- POLICIES (optional) --}}
    @if ($policiesBody !== null && trim(strip_tags($policiesBody)) !== '')
        <section style="margin-bottom: 1rem;">
            <header class="ca-sectionHeader">Policies</header>
            <div class="ca-conditions">{!! $policiesBody !!}</div>
        </section>
    @endif

    {{-- STUDENT ENDORSEMENT --}}
    <section style="margin-bottom: 1rem;">
        <header class="ca-sectionHeader">Student Endorsement — Signature Required</header>
        <div class="ca-conditions">{!! $studentBody !!}</div>
        <table style="width: 100%; margin-top: 0.5rem;">
            <tr>
                <td style="text-align: left;">
                    {{ $data->candidateFullName }} Signature:
                    @if (($candidateSignedAt ?? null) !== null)
                        <span class="ca-signature">{{ $data->candidateFullName }}</span>
                    @else
                        ________________________
                    @endif
                </td>
                <td style="text-align: right;">
                    Date: {{ ($candidateSignedAt ?? null) !== null ? $candidateSignedAt->format('M j, Y') : '_________' }}
                </td>
            </tr>
        </table>
        @if (($candidateSignedAt ?? null) !== null)
            <div class="ca-signature-note">Electronically signed {{ $candidateSignedAt->format('M j, Y g:ia') }}.</div>
        @endif
    </section>

    {{-- PARENT/GUARDIAN ENDORSEMENT --}}
    <section style="margin-bottom: 1rem;">
        <header class="ca-sectionHeader">Parent/Guardian Endorsement — Signature Required</header>
        <div class="ca-conditions">{!! $parentBody !!}</div>
        <table style="width: 100%; margin-top: 0.5rem;">
            <tr>
                <td style="text-align: left;">
                    Signature of {{ $data->emergencyContactName }}:
                    @if (($parentSignedAt ?? null) !== null)
                        <span class="ca-signature">{{ $data->emergencyContactName }}</span>
                    @else
                        ________________________
                    @endif
                </td>
                <td style="text-align: right;">
                    Date: {{ ($parentSignedAt ?? null) !== null ? $parentSignedAt->format('M j, Y') : '_________' }}
                </td>
            </tr>
        </table>
        @if (($parentSignedAt ?? null) !== null)
            <div class="ca-signature-note">Electronically signed {{ $parentSignedAt->format('M j, Y g:ia') }} (by the candidate, on the parent/guardian's behalf).</div>
        @endif
    </section>

    {{-- TEACHER/PRINCIPAL ENDORSEMENT (Pdf mode only) --}}
    @if ($showTeacherSection)
        <section style="margin-bottom: 1rem;">
            <header class="ca-sectionHeader">Teacher/Principal Endorsement — Signatures Required</header>
            <div class="ca-conditions">{!! $teacherBody !!}</div>
            <table style="width: 100%; margin-top: 0.5rem;">
                <tr>
                    <td style="text-align: left;">{{ $data->teacherFullName }} Signature: ________________________________________________</td>
                    <td style="text-align: right;">Date: _________</td>
                </tr>
                <tr>
                    <td style="text-align: left;">Principal Signature: ________________________________________________</td>
                    <td style="text-align: right;">Date: _________</td>
                </tr>
            </table>
        </section>
    @endif
</div>
