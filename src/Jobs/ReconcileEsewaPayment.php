<?php

namespace AjayMahato\Esewa\Jobs;

use AjayMahato\Esewa\Exceptions\EsewaException;
use AjayMahato\Esewa\Facades\Esewa;
use AjayMahato\Esewa\Models\EsewaPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for payments whose browser callback never arrived.
 *
 * Customers close tabs, lose signal and hit back. eSewa's status endpoint is the
 * authority on what actually happened, so this asks it.
 *
 * Unique per transaction, so an application that both auto-dispatches on payment
 * and runs the scheduled sweep does not double-poll the same record.
 */
class ReconcileEsewaPayment implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly string $transactionUuid) {}

    public function uniqueId(): string
    {
        return $this->transactionUuid;
    }

    /** Stop holding the unique lock once the job stops retrying. */
    public function uniqueFor(): int
    {
        return 3600;
    }

    public function handle(): void
    {
        $payment = EsewaPayment::query()->forTransaction($this->transactionUuid)->first();

        if (! $payment) {
            Log::warning("[esewa] Cannot reconcile unknown transaction \"{$this->transactionUuid}\".");

            return;
        }

        // Already settled - nothing eSewa can tell us would change it.
        if ($payment->status->isTerminal()) {
            return;
        }

        try {
            Esewa::reconcile($payment);
        } catch (EsewaException $e) {
            Log::warning("[esewa] Reconciliation of {$this->transactionUuid} failed: {$e->getMessage()}");

            throw $e;
        }
    }
}
