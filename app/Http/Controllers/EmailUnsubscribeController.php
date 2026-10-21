<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UnsubscribableEmail;
use App\Models\EmailUnsubscribe;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Signed, unauthenticated unsubscribe links from the scheduled digest emails.
 *
 * GET only shows a confirmation page — corporate/school mail scanners prefetch
 * links, so a GET must never change anything. POST does the unsubscribe, from
 * either the page's button or a mailbox provider's RFC 8058 one-click
 * List-Unsubscribe request (which carries no CSRF token, hence the exemption
 * in bootstrap/app.php; the URL signature is the authorization).
 */
class EmailUnsubscribeController extends Controller
{
    public function show(Request $request, User $user, UnsubscribableEmail $email): View
    {
        return view('email-unsubscribe', [
            'email' => $email,
            'action' => $request->fullUrl(),
            'unsubscribed' => $user->isUnsubscribedFrom($email),
        ]);
    }

    public function unsubscribe(Request $request, User $user, UnsubscribableEmail $email): View|Response
    {
        EmailUnsubscribe::firstOrCreate(['user_id' => $user->id, 'email_type' => $email->value]);

        if ($request->input('List-Unsubscribe') === 'One-Click') {
            return response()->noContent(200);
        }

        return view('email-unsubscribe', [
            'email' => $email,
            'action' => $request->fullUrl(),
            'unsubscribed' => true,
        ]);
    }
}
