<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DirectorUploadTemplateController extends Controller
{
    public function __invoke(Version $version, VersionRoleAssignmentService $roles): BinaryFileResponse
    {
        abort_unless($roles->canManageEvent(Auth::user(), $version->event), 403);

        return response()->download(
            resource_path('templates/director-upload-template.csv'),
            'director-upload-template.csv',
            ['Content-Type' => 'text/csv'],
        );
    }
}
