<?php

namespace AjayMahato\Esewa\Models;

use AjayMahato\Esewa\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One attempt to collect money through eSewa.
 *
 * A row is created when the customer is sent to eSewa and updated when the
 * gateway tells us what happened - either through the browser callback or
 * through a status check.
 *
 * @property int $id
 * @property string $transaction_uuid
 * @property string $product_code
 * @property string $amount
 * @property string $tax_amount
 * @property string $service_charge
 * @property string $delivery_charge
 * @property string $total_amount
 * @property PaymentStatus $status
 * @property string|null $ref_id
 * @property Carbon|null $verified_at
 * @property string|null $payable_type
 * @property string|null $payable_id
 * @property array<string, mixed>|null $raw_response
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Model|null $payable
 */
class EsewaPayment extends Model
{
    protected $fillable = [
        'transaction_uuid',
        'product_code',
        'amount',
        'tax_amount',
        'service_charge',
        'delivery_charge',
        'total_amount',
        'status',
        'ref_id',
        'verified_at',
        'payable_type',
        'payable_id',
        'raw_response',
        'meta',
    ];

    /**
     * Amounts cast to `decimal:2` come back as strings. That is deliberate:
     * comparing money as floats is how paisa goes missing.
     *
     * Declared as a property rather than the newer `casts()` method because the
     * property is honoured by every supported Laravel version, and one
     * declaration cannot drift out of step with another.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'service_charge' => 'decimal:2',
        'delivery_charge' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'status' => PaymentStatus::class,
        'verified_at' => 'datetime',
        'raw_response' => 'array',
        'meta' => 'array',
    ];

    public function getTable(): string
    {
        return $this->table ?? (string) config('esewa.database.table', 'esewa_payments');
    }

    public function getConnectionName(): ?string
    {
        return $this->connection ?? config('esewa.database.connection');
    }

    /**
     * The order, booking or invoice this payment belongs to.
     *
     * @return MorphTo<Model, $this>
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    // --------------------------------------------------------------- state

    public function isComplete(): bool
    {
        return $this->status->isComplete();
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function isFailed(): bool
    {
        return $this->status->isFailed();
    }

    /**
     * Where the customer should land after this payment resolved.
     */
    public function redirectUrl(bool $successful): ?string
    {
        $key = $successful ? 'success_redirect' : 'failure_redirect';
        $meta = $this->meta;

        $stored = is_array($meta) ? ($meta[$key] ?? null) : null;

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    // -------------------------------------------------------------- scopes

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeStatus(Builder $query, PaymentStatus|string $status): Builder
    {
        return $query->where('status', $status instanceof PaymentStatus ? $status->value : $status);
    }

    /**
     * Payments that may still change and are therefore worth reconciling.
     *
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PaymentStatus::PENDING->value,
            PaymentStatus::AMBIGUOUS->value,
        ]);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeForTransaction(Builder $query, string $transactionUuid): Builder
    {
        return $query->where('transaction_uuid', $transactionUuid);
    }
}
