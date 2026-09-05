<?php

namespace AjayMahato\Esewa\Console;

use AjayMahato\Esewa\Exceptions\EsewaException;
use AjayMahato\Esewa\Models\EsewaPayment;
use AjayMahato\Esewa\PaymentManager;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Sweep unresolved payments and ask eSewa what happened to each.
 *
 * Schedule it as a backstop:
 *
 *     Schedule::command('esewa:reconcile')->everyTenMinutes();
 */
class ReconcileCommand extends Command
{
    protected $signature = 'esewa:reconcile
        {transaction? : Reconcile only this transaction id}
        {--minutes=15 : Ignore payments started within the last N minutes}
        {--older-than=7 : Ignore payments older than N days}
        {--chunk=100 : Rows to load per batch}';

    protected $description = 'Reconcile pending eSewa payments against the gateway status endpoint';

    public function handle(PaymentManager $payments): int
    {
        if (is_string($transaction = $this->argument('transaction')) && $transaction !== '') {
            return $this->reconcileOne($payments, $transaction);
        }

        return $this->reconcileMany($payments);
    }

    private function reconcileOne(PaymentManager $payments, string $transaction): int
    {
        $payment = EsewaPayment::query()->forTransaction($transaction)->first();

        if (! $payment) {
            $this->components->error("No eSewa payment found for transaction \"{$transaction}\".");

            return self::FAILURE;
        }

        try {
            $payment = $payments->reconcile($payment);
        } catch (EsewaException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("{$transaction} is {$payment->status->value}.");

        return self::SUCCESS;
    }

    private function reconcileMany(PaymentManager $payments): int
    {
        // A payment the customer is still completing is not stale yet, so give
        // them a grace period before bothering the gateway about it.
        $before = Carbon::now()->subMinutes(max((int) $this->option('minutes'), 0));
        $after = Carbon::now()->subDays(max((int) $this->option('older-than'), 1));

        $query = EsewaPayment::query()
            ->unresolved()
            ->where('created_at', '<=', $before)
            ->where('created_at', '>=', $after);

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->components->info('No eSewa payments need reconciling.');

            return self::SUCCESS;
        }

        $this->components->info("Reconciling {$total} eSewa payment(s).");

        $resolved = 0;
        $failed = 0;

        $query->chunkById(max((int) $this->option('chunk'), 1), function ($chunk) use ($payments, &$resolved, &$failed) {
            foreach ($chunk as $payment) {
                try {
                    $updated = $payments->reconcile($payment);

                    if ($updated->status->isTerminal()) {
                        $resolved++;
                    }

                    $this->components->twoColumnDetail($payment->transaction_uuid, $updated->status->value);
                } catch (EsewaException $e) {
                    $failed++;
                    $this->components->twoColumnDetail($payment->transaction_uuid, '<fg=red>'.$e->getMessage().'</>');
                }
            }
        });

        $this->newLine();
        $this->components->info("Settled {$resolved} of {$total} payment(s).".($failed > 0 ? " {$failed} could not be checked." : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
