<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Finance\FinancePaymentLinkResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A payment's permanent deep link, for an external finance system to show.
 *
 * Keyed by the payment's public UUID alone, so the address never has to change
 * when the screens do. A signed-in reader who may see the payment is redirected
 * to its invoice screen; everyone else gets the same 404 a payment that does
 * not exist gets ({@see FinancePaymentLinkResolver}). A reader who is not
 * signed in is sent to sign in first, as on every other screen, and comes back
 * here afterwards.
 */
class FinancePaymentLinkController extends Controller
{
    public function __invoke(Request $request, string $payment, FinancePaymentLinkResolver $links): RedirectResponse
    {
        $reader = $request->user();
        abort_unless($reader instanceof User, 404);

        $destination = $links->destination($reader, $payment);
        abort_if($destination === null, 404);

        return redirect()->to($destination);
    }
}
