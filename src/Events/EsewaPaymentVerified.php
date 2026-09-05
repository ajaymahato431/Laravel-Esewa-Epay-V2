<?php

namespace AjayMahato\Esewa\Events;

use AjayMahato\Esewa\Models\EsewaPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * eSewa confirmed the payment as COMPLETE.
 *
 * This is the event to fulfil orders on. It fires at most once per payment even
 * when the browser callback and a reconciliation job race each other, because
 * the transition is applied under a row lock.
 */
class EsewaPaymentVerified
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly EsewaPayment $payment) {}
}
