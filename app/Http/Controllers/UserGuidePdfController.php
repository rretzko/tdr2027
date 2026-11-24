<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\VersionRoleAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Sidebar "User Guides" downloads (app.blade.php) — Student Guide and
 * Teacher Guide are open to every authenticated account; Adjudicator Guide
 * is for directors only (any account without a student record, the same
 * test app.blade.php uses for its portal split); Event Manager
 * Guide is shown only when VersionRoleAssignmentService::
 * hasActiveOrSandboxVersionRole() is true, but that's a UI nicety, so this
 * controller re-checks it independently in case the link is reached
 * directly. Redirects to a short-lived signed S3 URL rather than streaming
 * the binary through this app, same pattern as SharedScoresPdfController.
 */
class UserGuidePdfController extends Controller
{
    /** @var list<string> */
    private const ALLOWED_GUIDES = ['student-guide', 'teacher-guide', 'adjudicator-guide', 'event-manager-guide'];

    public function __construct(private readonly VersionRoleAssignmentService $versionRoles) {}

    public function __invoke(string $guide): RedirectResponse
    {
        abort_unless(in_array($guide, self::ALLOWED_GUIDES, true), 404);

        if ($guide === 'adjudicator-guide') {
            abort_if(Auth::user()->student !== null, 403);
        }

        if ($guide === 'event-manager-guide') {
            abort_unless($this->versionRoles->hasActiveOrSandboxVersionRole(Auth::user()), 403);
        }

        return redirect()->away(
            Storage::disk('s3')->temporaryUrl("tutorials/user-guides/{$guide}.pdf", now()->addMinutes(5)),
        );
    }
}
