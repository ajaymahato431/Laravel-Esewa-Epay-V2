<?php

namespace AjayMahato\Esewa\Events;

use AjayMahato\Esewa\Enums\PaymentStatus;
use AjayMahato\Esewa\Models\EsewaPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The payment moved from one status to another.
 *
 * Fires for every transition, including refunds, which the more specific events
 * do not cover.
 */
class EsewaPaymentStatusUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly EsewaPayment $payment,
        public readonly PaymentStatus $previousStatus,
    ) {}
}
