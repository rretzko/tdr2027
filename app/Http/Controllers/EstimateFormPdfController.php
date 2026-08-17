<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\School;
use App\Models\Version;
use App\Services\MailToAddressResolver;
use App\Support\EstimateFormData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * §5.13 — one PDF per (Version, School) for the requesting teacher. Kept off
 * the trackable EstimateForm Livewire route deliberately (see that
 * component's docblock) since it carries a {school} parameter.
 */
class EstimateFormPdfController extends Controller
{
    public function __invoke(Version $version, School $school, MailToAddressResolver $mailToResolver): Response
    {
        $teacher = Auth::user()->teacher;
        abort_if($teacher === null, 403);

        $isActiveSchool = $teacher->schools()
            ->wherePivot('is_active', true)
            ->wherePivot('verified_at', '!=', null)
            ->where('schools.id', $school->id)
            ->exists();

        abort_unless($isActiveSchool, 403);

        $data = EstimateFormData::build($version, $school, $teacher, $mailToResolver);

        return Pdf::loadView('pdf.estimate-form', ['data' => $data])
            ->setPaper('letter')
            ->download(Str::slug('estimate-form-'.($version->short_name ?? $version->name).'-'.$school->name).'.pdf');
    }
}
