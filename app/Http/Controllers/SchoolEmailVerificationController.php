<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Pivots\SchoolTeacher;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SchoolEmailVerificationController extends Controller
{
    public function verify(Request $request, SchoolTeacher $schoolTeacher): View
    {
        // The signed URL is only valid for the address it was sent to — if school_email
        // has since changed, this link no longer corresponds to a real verification.
        if ($schoolTeacher->school_email === null || $schoolTeacher->school_email !== $request->query('email')) {
            throw new NotFoundHttpException;
        }

        if ($schoolTeacher->verified_at === null) {
            $schoolTeacher->update(['verified_at' => now()]);
        }

        return view('school-email.verified', ['school' => $schoolTeacher->school]);
    }
}
