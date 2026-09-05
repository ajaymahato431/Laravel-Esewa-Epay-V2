<?php

namespace AjayMahato\Esewa\Concerns;

use AjayMahato\Esewa\Enums\PaymentStatus;
use AjayMahato\Esewa\Models\EsewaPayment;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Add to any model that gets paid for - an Order, Booking, Invoice or
 * Subscription.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasEsewaPayments
{
    /**
     * @return MorphMany<EsewaPayment, $this>
     */
    public function esewaPayments(): MorphMany
    {
        return $this->morphMany(EsewaPayment::class, 'payable');
    }

    /**
     * The most recent attempt, successful or not.
     *
     * @return MorphOne<EsewaPayment, $this>
     */
    public function latestEsewaPayment(): MorphOne
    {
        return $this->morphOne(EsewaPayment::class, 'payable')->latestOfMany();
    }

    /**
     * The attempt that actually paid, if there is one.
     *
     * @return MorphOne<EsewaPayment, $this>
     */
    public function completedEsewaPayment(): MorphOne
    {
        return $this->morphOne(EsewaPayment::class, 'payable')
            ->where('status', PaymentStatus::COMPLETE->value)
            ->latestOfMany();
    }

    /**
     * True once eSewa has confirmed payment.
     *
     * Prefer this over checking a cached column: it reflects the verified
     * gateway state rather than whatever your own record last remembered.
     */
    public function hasCompletedEsewaPayment(): bool
    {
        return $this->esewaPayments()
            ->where('status', PaymentStatus::COMPLETE->value)
            ->exists();
    }
}
