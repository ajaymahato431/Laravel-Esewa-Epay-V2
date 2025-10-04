<?php

namespace AjayMahato\Esewa\Events;

use Illuminate\Foundation\Events\Dispatchable;
use AjayMahato\Esewa\Models\EsewaPayment;

class EsewaPaymentVerified
{
    use Dispatchable;

    public function __construct(public EsewaPayment $payment) {}
}
