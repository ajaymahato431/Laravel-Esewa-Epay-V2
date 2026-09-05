<?php

namespace AjayMahato\Esewa\Enums;

/**
 * The transaction states eSewa reports.
 *
 * @see https://developer.esewa.com.np/pages/Epay
 */
enum PaymentStatus: string
{
    /** Not completed yet. The customer may still be paying. */
    case PENDING = 'PENDING';

    /** Paid. This is the only status that should fulfil an order. */
    case COMPLETE = 'COMPLETE';

    /** The full amount was returned to the customer. */
    case FULL_REFUND = 'FULL_REFUND';

    /** Part of the amount was returned to the customer. */
    case PARTIAL_REFUND = 'PARTIAL_REFUND';

    /** eSewa has the payment in a halt state. Re-check later; do not fulfil. */
    case AMBIGUOUS = 'AMBIGUOUS';

    /** eSewa has no record of the transaction - usually an expired session. */
    case NOT_FOUND = 'NOT_FOUND';

    /** Reversed by eSewa. */
    case CANCELED = 'CANCELED';

    /**
     * Resolve a status from anything eSewa or a database column might hold,
     * falling back to PENDING rather than throwing.
     *
     * Unknown values are treated as PENDING because "we do not know" must never
     * be mistaken for "paid".
     */
    public static function fromResponse(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value) && ! is_int($value)) {
            return self::PENDING;
        }

        return self::tryFrom(strtoupper(trim((string) $value))) ?? self::PENDING;
    }

    /** The payment succeeded and the order may be fulfilled. */
    public function isComplete(): bool
    {
        return $this === self::COMPLETE;
    }

    /** Still in flight - check again later. */
    public function isPending(): bool
    {
        return $this === self::PENDING || $this === self::AMBIGUOUS;
    }

    /** Definitively not going to succeed. */
    public function isFailed(): bool
    {
        return $this === self::CANCELED || $this === self::NOT_FOUND;
    }

    /** Money went back to the customer. */
    public function isRefunded(): bool
    {
        return $this === self::FULL_REFUND || $this === self::PARTIAL_REFUND;
    }

    /**
     * No further status change is expected, so reconciliation can stop polling.
     *
     * AMBIGUOUS is deliberately not terminal: eSewa resolves it later.
     */
    public function isTerminal(): bool
    {
        return $this->isComplete() || $this->isFailed() || $this->isRefunded();
    }

    /** A message safe to show a customer. */
    public function label(): string
    {
        return match ($this) {
            self::COMPLETE => 'Payment successful',
            self::PENDING => 'Payment is still processing',
            self::AMBIGUOUS => 'Payment is being confirmed by eSewa',
            self::CANCELED => 'Payment was cancelled',
            self::NOT_FOUND => 'Payment session expired or was never completed',
            self::FULL_REFUND => 'Payment was fully refunded',
            self::PARTIAL_REFUND => 'Payment was partially refunded',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
