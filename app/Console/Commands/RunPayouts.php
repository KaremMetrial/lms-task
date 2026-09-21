<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Payouts\ClaimPayoutBatch;
use App\Actions\Payouts\FanOutPayoutBatch;
use App\Domain\Payouts\PayoutBatchStatus;
use App\Domain\Payouts\PayoutItemStatus;
use App\Jobs\Payouts\SendPayoutItem;
use App\Models\PayoutItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Pays instructors what they are owed.
 *
 * Safe to run twice, concurrently, or by hand while the schedule is also running.
 * Three independent reasons:
 *
 *   1. `payout_batches.period_key` is UNIQUE, so both runs resolve to one batch.
 *   2. Fan-out uses insertOrIgnore against UNIQUE (batch, instructor), so the second
 *      run creates zero items.
 *   3. Every send is a conditional UPDATE off 'pending', so a second dispatch for an
 *      already-claimed item exits without sending.
 *
 * The Cache lock below is NOT one of those reasons. It exists to avoid wasted work,
 * and it is stated as such because treating a distributed lock as a safety mechanism
 * is the mistake this whole design is built to avoid — locks expire, indexes do not.
 */
final class RunPayouts extends Command
{
    protected $signature = 'payouts:run
        {--period= : Payout period as YYYY-MM. Defaults to last month.}
        {--dry-run : Report what would be paid without dispatching anything.}
        {--sync : Process items inline instead of queueing them, for demos.}';

    protected $description = 'Claim a payout batch, fan it out to instructors, and dispatch the sends.';

    public function handle(ClaimPayoutBatch $claim, FanOutPayoutBatch $fanOut): int
    {
        $period = $this->resolvePeriod();

        if ($period === null) {
            $this->error('The --period option must look like YYYY-MM.');

            return self::FAILURE;
        }

        $this->components->info("Payout run for {$period}");

        // Politeness, not safety. If another run already holds it, that run will do
        // the work and this one exits rather than duplicating effort.
        $lock = Cache::lock("payouts:run:{$period}", 300);

        if (! $lock->get()) {
            $this->components->warn('Another payout run holds the lock for this period. Exiting.');
            $this->line('  Nothing is at risk — the unique indexes would have made a concurrent run safe anyway.');

            return self::SUCCESS;
        }

        try {
            $claimed = $claim->execute($period);
            $batch = $claimed->batch;

            $this->components->twoColumnDetail(
                'Batch',
                $claimed->wasCreated ? "#{$batch->id} created" : "#{$batch->id} already existed (replay)",
            );

            if ($batch->status === PayoutBatchStatus::Completed) {
                $this->components->warn("Batch {$period} is already completed. Nothing to do.");

                return self::SUCCESS;
            }

            $summary = $fanOut->execute($batch);

            $this->components->twoColumnDetail('Items created now', (string) $summary->itemsCreated);
            $this->components->twoColumnDetail('Items on batch in total', (string) $summary->totalItems);
            $this->components->twoColumnDetail('Skipped — below minimum', (string) $summary->skippedBelowMinimum);
            $this->components->twoColumnDetail('Skipped — not payable', (string) $summary->skippedNotPayable);

            if ($this->option('dry-run')) {
                $this->components->warn('Dry run: nothing dispatched.');
                $this->table(
                    ['Instructor', 'Amount', 'Status'],
                    $batch->items()->where('status', PayoutItemStatus::Pending)->limit(25)->get()
                        ->map(fn (PayoutItem $i): array => [
                            $i->instructor_id,
                            $i->amount_minor->format(),
                            $i->status->value,
                        ])->all(),
                );

                return self::SUCCESS;
            }

            $batch->update(['status' => PayoutBatchStatus::Processing]);

            $dispatched = $this->dispatchPending($batch->id);

            // Labelled as what it is. On a replay, most of these jobs are already
            // queued from the first run and ShouldBeUnique silently drops the
            // duplicate — so "dispatched" would overstate the work. It is a count of
            // pending items handed to the queue, not of jobs actually enqueued.
            $this->components->twoColumnDetail('Pending items handed to the queue', (string) $dispatched);

            if (! $claimed->wasCreated && $dispatched > 0) {
                $this->line(
                    '  <fg=gray>Replay: jobs already queued by the earlier run are de-duplicated by their '
                    .'unique lock, and every send is guarded by a conditional update either way.</>'
                );
            }
            $this->components->info('Done. Outcomes settle asynchronously; run payouts:reconcile to resolve unknowns.');

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    private function dispatchPending(int $batchId): int
    {
        $dispatched = 0;
        $sync = (bool) $this->option('sync');

        // chunkById, so the cursor never re-reads a row whose status we just changed.
        PayoutItem::query()
            ->where('payout_batch_id', $batchId)
            ->where('status', PayoutItemStatus::Pending)
            ->chunkById(500, function ($items) use (&$dispatched, $sync): void {
                foreach ($items as $item) {
                    $sync
                        ? SendPayoutItem::dispatchSync($item->id)
                        : SendPayoutItem::dispatch($item->id);

                    $dispatched++;
                }
            });

        return $dispatched;
    }

    private function resolvePeriod(): ?string
    {
        $period = $this->option('period');

        if ($period === null) {
            // Last month: a period only becomes fully accrued once it has ended.
            return now()->subMonthNoOverflow()->format('Y-m');
        }

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $period) === 1
            ? (string) $period
            : null;
    }
}
