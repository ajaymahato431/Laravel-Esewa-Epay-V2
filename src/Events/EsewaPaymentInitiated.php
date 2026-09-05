<?php

namespace AjayMahato\Esewa\Events;

use AjayMahato\Esewa\Models\EsewaPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A payment row was created and the customer is being sent to eSewa.
 *
 * Nothing has been paid yet. Useful for audit logging or marking your own
 * record as "awaiting payment".
 */
class EsewaPaymentInitiated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly EsewaPayment $payment) {}
}
