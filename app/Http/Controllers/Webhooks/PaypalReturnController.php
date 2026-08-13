<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\Vendor;
use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\PaymentTransaction;
use App\Models\Version;
use App\Services\Payments\PaypalPaymentGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where PayPal sends the payer's browser back after they approve an order —
 * see epayment-integration.md §2.3 and PaypalPaymentGateway's own docblock
 * for why PayPal (unlike Square) needs an explicit capture step here rather
 * than auto-capturing on approval.
 *
 * $version/$candidate are validated ids reconstructed into a route
 * server-side, never a raw trusted redirect URL — PaypalPaymentGateway
 * deliberately builds the PayPal return_url this way specifically to avoid
 * an open-redirect vector.
 */
class PaypalReturnController extends Controller
{
    public function __invoke(Request $request, PaypalPaymentGateway $gateway): RedirectResponse
    {
        $token = $request->query('token');
        abort_if(! is_string($token), 400, 'Missing PayPal order token.');

        $transaction = PaymentTransaction::where('vendor', Vendor::Paypal)
            ->where('vendor_transaction_id', $token)
            ->firstOrFail();

        $gateway->captureOrder($transaction);

        $version = Version::findOrFail((int) $request->query('version'));

        $candidateId = $request->query('candidate');

        if ($candidateId !== null) {
            $candidate = Candidate::where('id', (int) $candidateId)
                ->where('version_id', $version->id)
                ->firstOrFail();

            return redirect()->route('registrations.candidate', ['version' => $version, 'candidate' => $candidate]);
        }

        return redirect()->route('registrations.version', ['version' => $version]);
    }
}
