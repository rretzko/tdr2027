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
            font-size: 0.8rem;
        }

        .onePageFontSize {
            font-size: 0.66rem;
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
                     alt="{{ $dto['organizationName'] }} logo" height="60" width="60"/>
            </td>
            <td style="text-align: right; font-weight: bold;">
                <div class="flex flex-col justify-center">

                    <div class="text-right font-semibold">
                        {{ $dto['versionName'] }}
                    </div>

                </div>
            </td>
        </tr>
        </tbody>
    </table>

    <div class="flex flex-col text-center" style="margin-bottom: 1rem;">
        <div style="text-align: center; font-size: 0.8rem; font-weight: bold; text-transform: uppercase;">
            All endorsements must be signed in ink for this application to be accepted.
        </div>
        <div style="text-align: center; font-size: 0.8rem; font-weight: bold; text-transform: uppercase;">
            Give this Signed Application to your teacher.
        </div>

    </div>

    {{-- SUMMARY --}}
    <style>
        .summaryTbl table {
            width: 99%;
            margin: auto;
        }

        .summaryTbl td, th {
            border: 1px solid black;
            text-align: center;
        }
    </style>
    <table class="summaryTbl" style="border-collapse: collapse; width: 99%; margin: auto; margin-bottom: 0.5rem;">
        <tbody>
        <tr>
            <td>{{ $dto['fullNameAlpha'] }}</td>
            <td style="font-size: 24px; font-weight: bold; color: black;">{{ $dto['candidateVoicepartDescr'] }}</td>
            <td>Grade: {{ $dto['grade'] }}</td>
            <td>{{ $dto['schoolShortName'] }}</td>
        </tr>
        </tbody>
    </table>

    <table class="summaryTbl" style="border-collapse: collapse; width: 99%; margin: auto; margin-bottom: 0.5rem;">
        <tbody>
        <tr style="background-color: lightgray;">
            <th>Home Phone</th>
            <th>Student Cell Phone</th>
            <th>Parent Cell Phone</th>
        </tr>
        <tr>
            <td>{{ $dto['studentPhoneHome'] }}</td>
            <td>{{ $dto['phoneMobile'] }}</td>
            <td style="@if(strstr($dto['emergencyContactMobile'], 'found')) color:red; @endif">
                {!! $dto['emergencyContactMobile'] !!}
            </td>
        </tr>
        </tbody>
    </table>


    {{-- STUDENT ENDORSEMENT --}}
    <section id="studentEndorsement" style="margin-bottom: 1rem;">

        <header class="sectionHeader" style="text-tranform: uppercase;">
            Student Endorsement - Signature Required
        </header>

        <div class="conditions">
            <div class="flex flex-col justify-self-stretch mx-4 mb-4 onePageFontSize" style="text-align: justify;">
                I agree to accept the decision of the judges as binding and if selected I will accept membership in this
                organization. I understand that membership in this organization may be terminated by anyone that has
                endorsed this application if I fail to comply with the rules set forth, or if I fail to attend
                rehearsals
                for any reason not accepted, in advance, by the CJMEA Committee. I understand that I must be a member
                of {{ $dto['schoolName'] }}'s musical performing organization. I further understand that I must remain
                an active
                member of {{ $dto['schoolName'] }}'s performing group, in good standing, throughout my CJMEA Region II
                Choral
                experience. I have read, understand, and will adhere to the required attendance dates and policy.

            </div>

            {{-- SIGNATURES --}}
            <div class="signatures onePageFontSize" style="margin-top: 0.5rem;">

                <table style="width: 100%;">
                    <tr>
                        <td style="text-align: left">
                            {{ $dto['fullName'] }} Signature: ________________________
                        </td>
                        <td style="text-align: right">
                            Date: _________
                        </td>
                    </tr>
                </table>

            </div>

        </div>{{-- end of class=conditions --}}

    </section>
    {{-- END OF STUDENT ENDORSEMENT --}}

    {{-- PARENT ENDORSEMENT --}}
    <section id="parentEndorsement" style="margin-bottom: 1rem;">

        <header class="sectionHeader" style="text-tranform: uppercase;">
            Parent/Legal Guardian Endorsement - Signature Required
        </header>

        <div class="conditions">
            <div class="flex flex-col justify-self-stretch mx-4 mb-4">
                <p class="mb-2 onePageFontSize" style="text-align: justify;">
                    As a parent or legal guardian of {{ $dto['fullName'] }}, I give permission for {{ $dto['first'] }}
                    to be an applicant
                    for this organization. I understand that neither {{ $dto['schoolName'] }} nor CJMEA assumes
                    responsibility for
                    illness or accident. I further attest to the statement signed by {{ $dto['fullName'] }} and will
                    assist {{ $dto['first'] }}
                    in fulfilling the obligations incurred. I will encourage and assist {{ $dto['first'] }} in complying
                    with the
                    attendance policy as set forth in this document. I also give permission to CJMEA to
                    use {{ $dto['first'] }}'s photograph
                    for publicity publication in print and online.
                </p>
                <p class="mb-2 onePageFontSize" style="text-align: justify;">
                    I have read and acknowledged the rehearsal and concert schedule and I will make arrangements to pick
                    up
                    {{ $dto['first'] }} on or within twenty-minutes after posted rehearsal dismissal time.
                </p>
            </div>

            {{-- SIGNATURES --}}
            <div class="signatures onePageFontSize" style="margin-top: 0.5rem;">

                <table style="width: 100%;">
                    <tr>
                        <td style="text-align: left">
                            Signature of {{ $dto['emergencyContactName'] }}: ________________________
                        </td>
                        <td style="text-align: right">
                            Date: _________
                        </td>
                    </tr>
                </table>

            </div>

        </div>{{-- end of class=conditions --}}

    </section>
    {{-- END OF PARENT ENDORSEMENT --}}

    {{-- PRINCIPAL/TEACHER ENDORSEMENT --}}
    <section id="teacherEndorsement" style="margin-bottom: 1rem;">

        <header class="sectionHeader" style="text-tranform: uppercase;">
            Director/Principal's Endorsement - Signatures Required
        </header>

        <div class="conditions">
            <div class="flex flex-col justify-self-stretch mx-4 mb-4 onePageFontSize" style="text-align: justify;">

                We, the undersigned, recommend {{ $dto['fullName'] }} for participation in this CJMEA sponsored
                activity.
                {{ $dto['first'] }} is a qualified candidate for this activity and is presently enrolled in
                grade {{ $dto['grade'] }}
                at {{ $dto['schoolName'] }}. We understand, in order to audition, that {{ $dto['first'] }}:
                <ol class="ml-8 list-decimal text-sm">
                    <li style="text-align: justify;">
                        is a member of {{ $dto['schoolName'] }}'s musical performing organization. By this we mean that
                        a
                        student auditioning for chorus must be a member of the {{ $dto['schoolName'] }} choral program,
                        and a student auditioning for band or orchestra must be a member of {{ $dto['schoolName'] }}
                        instrumental program.<br/>
                        OR
                    </li>
                    <li style="text-align: justify;">
                        does not have a corresponding school musical performing organization at the school or home
                        school where they attend but that we know this student and will attest to their ability and
                        character.
                    </li>
                </ol>
                </p>

                <p class="my-2 onePageFontSize" style="text-align: justify;">
                    A CJMEA Region II Chorus member must remain an active member, in good standing, of the school
                    performing
                    organization throughout the CJMEA Region II Chorus experience. We understand
                    that {{ $dto['teacherFullName'] }},
                    sponsoring this student, is a paid member of NAfME and will
                    serve and complete the assignment given to them by the audition chairperson. We also understand that
                    we will review this application to be sure that all parts are completed correctly. In the event that
                    {{ $dto['fullName'] }} is accepted into the group, we will use our influence to see
                    that {{ $dto['first'] }} is
                    properly prepared and that {{ $dto['first'] }} adheres to the rules, regulations, and policies
                    printed on
                    this application and set forth by the performing groups.
                </p>

            </div>

            {{-- SIGNATURES --}}
            <div class="signatures onePageFontSize" style="margin-top: 0.5rem;">

                <table style="width: 100%;">
                    <tr>
                        <td style="text-align: left">
                            Signature of {{ $dto['teacherFullName'] }}: ________________________
                        </td>
                        <td style="text-align: center;">
                            Signature of Principal: ________________________
                        </td>
                        <td style="text-align: right">
                            Date: _________
                        </td>
                    </tr>
                </table>

            </div>

        </div>{{-- end of class=conditions --}}

    </section>
    {{-- END OF TEACHER/PRINCIPAL ENDORSEMENT --}}

    {{--    <div class="pageBreak"></div>--}}

    {{-- HEADER --}}
    {{--    <table style="border-collapse: collapse; width: 99%; margin: auto; margin-bottom: 0.5rem;">--}}
    {{--        <tbody>--}}
    {{--        <tr>--}}
    {{--            <td>--}}
    {{--                <img src="{{ Storage::disk('s3')->url($dto['logoPdf']) }}"--}}
    {{--                     alt="{{ $dto['organizationName'] }} logo {{ $dto['logo'] }}" height="60" width="60"/>--}}
    {{--            </td>--}}
    {{--            <td style="text-align: right; font-weight: bold;">--}}
    {{--                <div class="flex flex-col justify-center">--}}

    {{--                    <div class="text-right font-semibold">--}}
    {{--                        {{ $dto['versionShortName'] }}--}}
    {{--                    </div>--}}
    {{--                    <div class="text-right">--}}
    {{--                        Student Application Form--}}
    {{--                    </div>--}}
    {{--                </div>--}}
    {{--            </td>--}}
    {{--        </tr>--}}
    {{--        </tbody>--}}
    {{--    </table>--}}

    {{--    <div class="flex flex-col text-center">--}}
    {{--        <div style="text-align: center; font-size: 0.8rem; font-weight: bold; text-transform: uppercase;">--}}
    {{--            All endorsements must be signed in ink for this application to be accepted.--}}
    {{--        </div>--}}
    {{--        <div style="text-align: center; font-size: 0.8rem; font-weight: bold; text-transform: uppercase;">--}}
    {{--            Give this Signed Application to your teacher.--}}
    {{--            <br/>--}}
    {{--            Page 2/2--}}
    {{--        </div>--}}

    {{--        <div style="text-align: center; font-size: 0.8rem;height: 1rem;">--}}
    {{--            --}}{{-- Page 1 of 2 --}}
    {{--        </div>--}}
    {{--    </div>--}}

    {{-- SUMMARY --}}
    {{--    <style>--}}
    {{--        .summaryTbl table {--}}
    {{--            width: 99%;--}}
    {{--            margin: auto;--}}
    {{--        }--}}

    {{--        .summaryTbl td, th {--}}
    {{--            border: 1px solid black;--}}
    {{--            text-align: center;--}}
    {{--        }--}}
    {{--    </style>--}}
    {{--    <table class="summaryTbl" style="border-collapse: collapse; width: 99%; margin: auto; margin-bottom: 0.5rem;">--}}
    {{--        <tbody>--}}
    {{--        <tr>--}}
    {{--            <td>{{ $dto['fullNameAlpha'] }}</td>--}}
    {{--            <td style="color: red;">{{ $dto['candidateVoicepartDescr'] }}</td>--}}
    {{--            <td>Grade: {{ $dto['grade'] }}</td>--}}
    {{--            <td>{{ $dto['schoolShortName'] }}</td>--}}
    {{--        </tr>--}}
    {{--        </tbody>--}}
    {{--    </table>--}}

    {{--    <table class="summaryTbl" style="border-collapse: collapse; width: 99%; margin: auto; margin-bottom: 0.5rem;">--}}
    {{--        <tbody>--}}
    {{--        <tr style="background-color: lightgray;">--}}
    {{--            <th>Home Phone</th>--}}
    {{--            <th>Student Cell Phone</th>--}}
    {{--            <th>Parent Cell Phone</th>--}}
    {{--        </tr>--}}
    {{--        <tr>--}}
    {{--            <td>{{ $dto['studentPhoneHome'] }}</td>--}}
    {{--            <td>{{ $dto['phoneMobile'] }}</td>--}}
    {{--            <td style="@if(strstr($dto['emergencyContactMobile'], 'found')) color:red; @endif">--}}
    {{--                {!! $dto['emergencyContactMobile'] !!}--}}
    {{--            </td>--}}
    {{--        </tr>--}}
    {{--        </tbody>--}}
    {{--    </table>--}}

    {{-- PLEASE NOTE --}}
    <section id="pleaseNote">

        <header style="text-tranform: uppercase;">
            <u>Please Note</u>
        </header>

        <div class="conditions" style="font-size: 0.8rem;">
            <p class="mb-2 onePageFontSize" style="text-align: justify; ">
                A student will not be excused for any types of performance other than one school performance with the
                corresponding type of CJMEA organization. For example: If the student is in the CJMEA Region II Chorus,
                the
                student may be excused from a CJMEA Region II Chorus rehearsal (excluding the dress rehearsal) to
                perform with
                his/her high school choir. No one may miss the concert weekend rehearsals for any reason.
            </p>
            <p class="mb-2 onePageFontSize" style="text-align: justify;">
                Audition fees must be paid using SQUARE online.
                <br/>
                All accepted students will be charged a ${{ $dto['participationFee'] }} acceptance fee which
                must
                be paid using SQUARE online. This fee will cover the cost of the purchase of
                music.
            </p>
        </div>
    </section>

    {{-- HOME SCHOOL --}}
    <section id="homeSchool">

        <header style="text-tranform: uppercase;">
            <u>Attention Home School Students and Directors</u>
        </header>

        <div class="conditions" style="font-size: 0.8rem;">
            <p class="mb-2 onePageFontSize" style="text-align: justify;">
                Please read the special Home School Instructions included in the information section of the Director's
                Packet BEFORE you complete this form.
            </p>
        </div>
    </section>

</div>
