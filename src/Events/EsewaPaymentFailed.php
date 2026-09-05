<?php

namespace AjayMahato\Esewa\Events;

use AjayMahato\Esewa\Models\EsewaPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The payment reached a state it will not recover from.
 *
 * Either the customer cancelled, or eSewa has no record of the transaction and
 * the session has expired. Release reserved stock here.
 */
class EsewaPaymentFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly EsewaPayment $payment) {}
}
