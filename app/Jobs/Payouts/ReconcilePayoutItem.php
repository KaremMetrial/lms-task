<?php

declare(strict_types=1);

namespace App\Jobs\Payouts;

use App\Actions\Payouts\PayoutItemTransitions;
use App\Domain\Payouts\FailureClass;
use App\Domain\Payouts\Provider\Exceptions\ProviderTimeout;
use App\Domain\Payouts\Provider\PaymentProvider;
use App\Domain\Payouts\Provider\ProviderOutcome;
use App\Models\PayoutItem;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Resolves an item whose outcome we do not know, by ASKING — never by resending.
 *
 * This is the only escape from `unknown`, and it is deliberately a read. A status
 * check is safe to run any number of times, from any number of workers, which is
 * exactly the property the send path cannot have.
 *
 * The four answers and what each means:
 *
 *   Succeeded → the money moved after all. Settle it. If our first attempt timed
 *               out AFTER the transfer, this is where that money is finally
 *               accounted for — without a second transfer.
 *   Failed    → the provider decided against it. Release the hold; the amount
 *               returns to available and the next run picks it up.
 *   NotFound  → the provider never saw this key. Trustworthy precisely BECAUSE the
 *               key is deterministic: had it ever seen this payout, it would have
 *               seen this key. Safe to release.
 *   Pending   → still processing. Keep holding, ask again later.
 *
 * After `max_status_checks` inconclusive attempts the item is left in `unknown` for
 * a human. Guessing at that point would defeat the entire design, so the system
 * stops and says so instead.
 */
final class ReconcilePayoutItem implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public readonly int $payoutItemId)
    {
        $this->onQueue('payouts');
    }

    public function uniqueId(): string
    {
        return "reconcile-payout-item:{$this->payoutItemId}";
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    public function handle(PaymentProvider $provider, PayoutItemTransitions $transitions): void
    {
        $item = PayoutItem::query()->find($this->payoutItemId);

        if ($item === null || ! $item->status->needsStatusCheck()) {
            // Already terminal. Nothing to resolve.
            return;
        }

        $maxChecks = (int) config('ledger.payout.max_status_checks', 12);

        if ($item->attempts >= $maxChecks) {
            Log::error('Payout item exhausted its status checks and needs a human.', [
                'payout_item_id' => $item->id,
                'idempotency_key' => $item->idempotency_key,
                'attempts' => $item->attempts,
            ]);

            // Left in `unknown` with the reservation intact: the money is neither
            // paid nor released, which is the only honest position to hold.
            return;
        }

        try {
            $result = $provider->status($item->idempotency_key);
        } catch (ProviderTimeout $e) {
            // Even the status check can time out. Still no resend.
            $transitions->markUnknown($item, $this->nextCheckAt($item), $e->getMessage());
            $this->requeue($item);

            return;
        }

        match ($result->outcome) {
            ProviderOutcome::Succeeded => $transitions->settleAsSucceeded(
                $item,
                (string) $result->reference,
            ),

            ProviderOutcome::Failed => $transitions->releaseAsFailed(
                $item,
                $result->failureClass ?? FailureClass::Permanent,
                (string) ($result->message ?? 'Rejected by the provider.'),
            ),

            ProviderOutcome::NotFound => $transitions->releaseAsFailed(
                $item,
                FailureClass::Transient,
                'The provider holds no record of this key, so nothing was moved. '
                .'Released; the amount returns to the instructor\'s available balance.',
            ),

            ProviderOutcome::Pending => $this->keepWaiting($item, $transitions),
        };
    }

    private function keepWaiting(PayoutItem $item, PayoutItemTransitions $transitions): void
    {
        $transitions->rescheduleCheck($item, $this->nextCheckAt($item));
        $this->requeue($item);
    }

    private function requeue(PayoutItem $item): void
    {
        self::dispatch($item->id)->delay($this->nextCheckAt($item));
    }

    /**
     * Exponential-ish backoff from config, holding at the last step rather than
     * growing forever.
     */
    private function nextCheckAt(PayoutItem $item): CarbonImmutable
    {
        /** @var list<int> $schedule */
        $schedule = config('ledger.payout.status_check_backoff_seconds', [30]);

        $index = min($item->attempts, count($schedule) - 1);

        return CarbonImmutable::now()->addSeconds($schedule[max($index, 0)]);
    }
}
