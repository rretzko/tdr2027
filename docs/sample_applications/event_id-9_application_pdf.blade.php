<div class="mt-4">

    <style>
        .sectionHeader {
            background-color: lightblue;
            text-transform: uppercase;
            padding: 0 0.25rem;
            font-size: 1.0rem;
            font-weight: bold;
            margin-bottom: 0.25rem;
        }

        .conditions {
            font-size: 0.9rem;
        }

        .pageBreak {
            page-break-after: always;
        }
    </style>

    {{-- HEADER --}}
    <table style="border-collapse: collapse; width: 99%; margin: auto; margin-bottom: 0.5rem;">
        <tbody>
        <tr>
            <td>
                <img src="{{ Storage::disk('s3')->url($dto['logoPdf']) }}"
                     alt="{{ $dto['organizationName'] }} logo {{ $dto['logo'] }}" height="60" width="60"/>
            </td>
            <td>Logo: {{ $dto['logoPdf'] }}</td>
            <td style="">
                <div style="text-align: center;">

                <div style="font-weight: bold;">
                        {{ $dto['schoolShortName'] }}
                    </div>
                    <div class="text-center">
                        {{ $dto['versionShortName'] }}
                    </div>
                    <div class="text-center">
                        Student Application Form
                    </div>
                    <div class="text-center">
                        Page 1/2
                    </div>
                </div>
            </td>
            <td>
                <div style="border: 1px solid rgba(0,0,0, 0.3); color: rgba(0,0,0,0.5); text-align: center;">
                    ADMIN<br/>USE<br/>ONLY
                </div>
            </td>
        </tr>
        </tbody>
    </table>

    {{-- SUMMARY --}}
    <style>
        #summaryTbl table {
            width: 99%;
            margin: auto;
        }

        #summaryTbl td {
            border: 1px solid black;
            text-align: center;
        }
    </style>
    <table id="summaryTbl" style="border-collapse: collapse; width: 99%; margin: auto; margin-bottom: 0.5rem;">
        <tbody>
        <tr>
            <td>{{ $dto['fullNameAlpha'] }}</td>
            <td style="font-size: 24px; font-weight: bold; color: black;">
                {{ $dto['candidateVoicepartDescr'] }}
            </td>
            <td>Grade: {{ $dto['grade'] }}</td>
            <td>{{ $dto['schoolShortName'] }}</td>
        </tr>
        </tbody>
    </table>

    {{-- AUDITION FEE --}}
    <section id="auditionFee" style="text-align: right; font-weight: bold; margin-bottom: 0.5rem;">
        <div class="mr-2">THE AUDITION FEE IS: ${{ $dto['auditionFee'] }}</div>
    </section>

    {{-- STUDENT ENDORSEMENT --}}
    <section id="studentEndorsement" class="pageBreak">

        <header class="sectionHeader">
            Student Endorsement - Signatures Required
        </header>

        <div class="conditions">
            <div class="flex flex-col italic justify-self-stretch mx-4 mb-4">
                <b class="mb-2">In return for the privilege of participating in an NJMEA sponsored NJ All-State
                    Ensemble, I agree to
                    the following:</b>
                <ul class="ml-8 list-disc">
                    <style>li {
                            margin-bottom: .5rem;
                        }</style>
                    <li>
                        I, <b>{{ $dto['fullName'] }}</b>, agree to accept the decision of the
                        judges as binding. If selected, I will accept membership in the {{ $dto['versionShortName'] }}
                        for which I have auditioned. I also agree to pay the ${{ $dto['auditionFee'] }} (subject to
                        change) participation fee.
                        I understand that membership in this organization may be terminated
                        by the endorsers of my application if I fail to comply with the rules set forth or if
                        I fail to learn my music.
                    </li>
                    <li>
                        I understand that Mixed Chorus members are expected to attend all rehearsals from
                        October through November. Treble Chorus rehearsals are held in January through February. One
                        absence will result in testing at the following rehearsal.
                        An absence is defined as missing any scheduled rehearsal or any part thereof.
                        I further understand that all activities must be attended in their entirety.
                        I understand that it is not possible for me to be a member of the {{ $dto['versionShortName'] }}
                        and participate in fall activities including Conference/NJSIAA tournament games that may take
                        place before/during the completion of my {{ $dto['versionShortName'] }} obligations.
                        Failure to fulfill my {{ $dto['versionShortName'] }} obligations will result in disqualification
                        from any {{ $dto['organizationName'] }} sponsored event for the period of one year, up to and
                        including the applicable event. I understand that the manager, with the approval of the NJ
                        All-State Choral Procedures Committee, will resolve all serious conflicts and/or questionable
                        circumstances not specifically covered by the above.
                    </li>
                    <li>
                        I will respect the property of others, will act professionally, and will treat all members of
                        the
                        ensemble with respect.
                    </li>
                    <li>
                        I will learn all the music to the best of my ability. <b>Chorus members agree to memorize all
                            music.</b>
                    </li>
                    <li>
                        I will cooperate fully with managers, counselors, and all other administrative officials of the
                        {{ $dto['versionShortName'] }} and the New Jersey Music Educators
                        Association.
                    </li>
                    <li>
                        I will assume all responsibility for my music, folder, performance apparel, luggage and other
                        belongings at the sites of all rehearsals and concerts.
                    </li>
                    <li>
                        I will neither use nor have in my possession, at any time, alcoholic beverages, illegal drugs or
                        weapons of any kind.
                    </li>
                    <li>
                        I acknowledge that Mixed Chorus members may not also participate in any of these other
                        NJ All-State ensembles: Orchestra, Jazz Ensemble or Vocal Jazz Ensemble.
                        Treble Chorus members may not be a member of the NJ All-State Band.
                    </li>
                    <li>
                        I understand that a total evaluation of my {{ $dto['versionShortName'] }} experience is used to
                        determine any
                        recommendation for the Governor's Award, All-Eastern and/or National High School Ensembles. In
                        addition to my placement in the {{ $dto['versionShortName'] }}, such factors as behavior,
                        promptness and
                        preparedness for rehearsals will also be considered. I understand the Administrative
                        personnel with the approval of the {{ $dto['versionShortName'] }} Choral Procedures Committee(s)
                        will make these
                        recommendations.
                    </li>
                    <li>
                        I will adhere to all dates concerning fees/forms or any other deadlines requested for my
                        participation.
                    </li>
                    <li>
                        I understand that {{ $dto['versionShortName'] }} members are required to comply with all
                        obligations set
                        forth above. Non-compliance with any provision contained herein shall constitute a breach of
                        this Agreement and shall serve as the basis of the participant's immediate termination and
                        exclusion from all performances.
                    </li>
                    <li>
                        I further understand that as a {{ $dto['versionShortName'] }} member, I must remain an active
                        member in
                        good standing with the school ensemble that corresponds to my {{ $dto['versionShortName'] }}
                        ensemble throughout
                        my entire All-State experience.
                    </li>
                </ul>

            </div>{{-- end of class=conditions --}}

            {{-- SIGNATURES --}}
            <table style="width: 100%; font-weight: bold;">
                <tr>
                    <td style="text-align: left; width: 50%;">
                        STUDENT SIGNATURE: ________________________
                    </td>
                    <td style="text-align: right; width: 50%;">
                        DATE: _________
                    </td>
                </tr>
            </table>

        </div>
    </section>
    {{-- END OF STUDENT ENDORSEMENT --}}

    {{-- PAGE BREAK HERE --}}

    {{-- START PAGE 2 --}}
    {{-- HEADER --}}
    <table style="border-collapse: collapse; width: 99%; margin: auto; margin-bottom: 0.5rem;">
        <tbody>
        <tr>
            <td>
                <img src="{{ Storage::disk('s3')->url($dto['logoPdf']) }}"
                     alt="{{ $dto['organizationName'] }} logo {{ $dto['logo'] }}" height="60" width="60"/>
            </td>
            <td style="">
                <div style="text-align: center;">
                    <div style="font-weight: bold;">
                        {{ $dto['schoolShortName'] }}
                    </div>
                    <div class="text-center">
                        {{ $dto['versionShortName'] }}
                    </div>
                    <div class="text-center">
                        Student Application Form
                    </div>
                    <div class="text-center">
                        Page 2/2
                    </div>
                </div>
            </td>
            <td>
                <div style="border: 1px solid rgba(0,0,0, 0.3); color: rgba(0,0,0,0.5); text-align: center;">
                    ADMIN<br/>USE<br/>ONLY
                </div>
            </td>
        </tr>
        </tbody>
    </table>

    {{-- SUMMARY --}}
    <style>
        #summaryTbl table {
            width: 99%;
            margin: auto;
        }

        #summaryTbl td {
            border: 1px solid black;
            text-align: center;
        }
    </style>
    <table id="summaryTbl" style="border-collapse: collapse; width: 99%; margin: auto; margin-bottom: 0.5rem;">
        <tbody>
        <tr>
            <td>{{ $dto['fullNameAlpha'] }}</td>
            <td style="color: red;">{{ $dto['candidateVoicepartDescr'] }}</td>
            <td>Grade: {{ $dto['grade'] }}</td>
            <td>{{ $dto['schoolShortName'] }}</td>
        </tr>
        </tbody>
    </table>

    {{-- AUDITION FEE --}}
    <section id="auditionFee" style="text-align: right; font-weight: bold; margin-bottom: 0.5rem;">
        <div class="mr-2">THE AUDITION FEE IS: ${{ $dto['auditionFee'] }}</div>
    </section>


    {{-- GUARDIAN ENDORSEMENT --}}
    <section id="guardianEndorsement" style="margin-bottom: 1rem;">

        <header class="sectionHeader ">
            Parent/Legal Guardian Endorsement - Signatures Required
        </header>

        <div class="conditions" style="margin-bottom: 0.5rem;">
            <div class="italic justify-self-stretch mx-4 mb-4">
                As the parent or legal guardian of <b>{{ $dto['fullName'] }}</b>, I declare that I have
                read the endorsement, which {{ $dto['first'] }} has signed, and I give permission
                for {{ $dto['pronounObject'] }} to audition to become a member of the
                {{ $dto['versionShortName'] }}. I promise to assist {{ $dto['first'] }} in
                fulfilling the {{ $dto['versionShortName'] }} obligations and in meeting any expenses necessary for
                rehearsals and concerts. I
                understand it is the policy of {{ $dto['organizationName'] }} that if an All-State student is
                incapacitated in any way that
                requires additional assistance, it will be the responsibility of the All-State student's
                parent/guardian/school
                to provide the necessary help at all rehearsals, meals, concerts, etc. The provided chaperone will be
                housed with the student and will be charged the regular student housing fee.
            </div>
        </div>{{-- end of class=conditions --}}

        <div class="signatures">

            <table style="width: 100%; font-weight: bold;">
                <tr>
                    <td style="text-align: left; width: 75%;">
                        SIGNATURE OF {{ strtoupper($dto['emergencyContactName']) }}: ________________________ <br/>
                        <span
                            style="font-size: 0.8rem;">{{ strtoupper($dto['emergencyContactName']) }} CELL PHONE: <span
                                style="@if(strstr($dto['emergencyContactMobile'], "(")) color: red @endif "
                            >
                                {!! $dto['emergencyContactMobile'] !!}
                            </span>
                        </span>
                    </td>
                    <td style="text-align: right; width: 25%;">
                        DATE: _________
                    </td>
                </tr>
            </table>

        </div>

    </section>
    {{-- END OF GUARDIAN ENDORSEMENT --}}

    {{-- TEACHER ENDORSEMENT --}}
    <section id="teacherEndorsement" class="mb-2">

        <header class="sectionHeader">
            Principal/Teacher Endorsement - Signatures Required
        </header>

        <div class="conditions" style="margin-bottom: 0.5rem;">

            <div class="italic justify-self-stretch mx-4 mb-4">
                We recommend <b>{{ $dto['fullName'] }}</b> for participation in the {{ $dto['versionShortName'] }}.
                <b>{{ $dto['first'] }}</b> is a qualified candidate in good
                standing in {{ $dto['pronounPossessive'] }} Choral Department and is presently
                enrolled in grade {{ $dto['grade'] }} at {{ $dto['schoolName'] }}.
                We understand that <b>{{ $dto['teacherFullName'] }}</b>, who is sponsoring <b>{{ $dto['fullName'] }}</b>,
                is a current (paid) member of the National Association of Music Educators (NAfME), and is required to
                participate as a JUDGE FOR ONLINE AUDITIONS, as described in the Directors's Packet.

                We will review this application to ensure that all parts are complete and accurate. This application
                will be mailed to the Registration Manager postmarked by the application postmark deadline of
                <b>{{ $dto['applicationDeadline'] }}</b>.
                LATE APPLICATIONS WILL NOT BE ACCEPTED and all deadlines are non-negotiable.
                If <b>{{ $dto['fullName'] }}</b> is accepted, we will ensure that <b>{{ $dto['first'] }}</b> is prepared
                and adheres to the rules and regulations set forth by the {{ $dto['organizationName'] }}.

            </div>

        </div>{{-- end of class=conditions --}}

        {{-- SIGNATURES --}}
        <div class="signatures">

            <table style="width: 100%; font-weight: bold;">
                <tr>
                    <td style="text-align: left; width: 75%; height: 6rem;">
                        PRINCIPAL SIGNATURE: ________________________
                    </td>
                    <td style="text-align: right; width: 25%; height: 6rem;">
                        DATE: _________
                    </td>
                </tr>
                <tr>
                    <td style="text-align: left; width: 75%;">
                        {{ strtoupper($dto['teacherFullName']) }} SIGNATURE: ________________________
                    </td>
                    <td style="text-align: right; width: 25%;">
                        DATE: _________
                    </td>
                </tr>

            </table>

        </div>

    </section>
    {{-- END OF TEACHER ENDORSEMENT --}}

</div>
