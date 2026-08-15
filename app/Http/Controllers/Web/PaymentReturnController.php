<?php

namespace App\Http\Controllers\Web;

use App\Domain\Payment\Services\TopupService;
use App\Domain\Wallet\Models\Payment;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Gateway redirect handling.
 *
 * The wallet is credited by TopupService::verify, never by this controller
 * reading the query string: a redirect is attacker-controllable, a verified
 * callback is not.
 */
class PaymentReturnController extends Controller
{
    public function __construct(private readonly TopupService $topups) {}

    /** Development-only page standing in for a real gateway's hosted form. */
    public function sandbox(Payment $payment): View
    {
        abort_if(app()->environment('production'), 404);

        return view('payments.sandbox', ['payment' => $payment]);
    }

    public function callback(Request $request, Payment $payment): View
    {
        $payment = $this->topups->verify($payment, $request->query());

        return view('payments.result', ['payment' => $payment->fresh()]);
    }

    public function pending(Payment $payment): View
    {
        return view('payments.result', ['payment' => $payment]);
    }
}
