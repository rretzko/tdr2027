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
        $founderId = $request->session()->pull('impersonator_id');

        if ($founderId === null) {
            abort(404);
        }

        Auth::login(User::findOrFail($founderId));

        return redirect()->route('founder.impersonate');
    }
}
