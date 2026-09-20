<?php

declare(strict_types=1);

namespace App\Actions\Payouts;

use App\Domain\Money\Money;
use App\Domain\Payouts\PayoutBatchStatus;
use App\Models\PayoutBatch;
use Illuminate\Database\QueryException;

/**
 * Gets the one batch for a period, creating it if nobody has yet.
 *
 * `payout_batches.period_key` is UNIQUE, so two servers starting the September run
 * in the same instant resolve to the SAME batch: one INSERT wins, the other catches
 * a duplicate-key error and reads the winner's row. There is never a window in
 * which two September batches exist, so there is nothing to reconcile afterwards.
 *
 * firstOrCreate alone would not be enough — it is a SELECT then an INSERT, and two
 * callers can both pass the SELECT. The unique index is what closes that window;
 * the catch below is just how we cooperate with it.
 */
final readonly class ClaimPayoutBatch
{
    public function execute(string $periodKey): BatchClaim
    {
        $existing = PayoutBatch::query()->where('period_key', $periodKey)->first();

        if ($existing !== null) {
            return BatchClaim::existing($existing);
        }

        try {
            $batch = PayoutBatch::query()->create([
                'period_key' => $periodKey,
                'status' => PayoutBatchStatus::Pending,
                'currency' => config('ledger.currency'),
                'minimum_payout_minor' => Money::of(
                    (int) config('ledger.payout.minimum_minor'),
                    (string) config('ledger.currency'),
                ),
                'items_count' => 0,
                'total_minor' => 0,
                'started_at' => now(),
            ]);

            return BatchClaim::created($batch);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062 && $e->getCode() !== '23000') {
                throw $e;
            }

            // Lost the race, which is a correct outcome. Attach to the winner.
            return BatchClaim::existing(
                PayoutBatch::query()->where('period_key', $periodKey)->firstOrFail()
            );
        }
    }
}
