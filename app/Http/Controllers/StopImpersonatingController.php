<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StopImpersonatingController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $originalUserId = $request->session()->pull('impersonator_id');

        if ($originalUserId === null) {
            abort(404);
        }

        $scope = $request->session()->pull('impersonation_scope');
        $versionId = $request->session()->pull('impersonation_version_id');
        $request->session()->pull('impersonation_candidate_id');

        Auth::login(User::findOrFail($originalUserId));

        if (in_array($scope, ['web_registration_manager', 'event_manager_student_preview'], true) && $versionId !== null) {
            return redirect()->route('events.versions.web-registration', $versionId);
        }

        return redirect()->route('founder.impersonate');
    }
}
