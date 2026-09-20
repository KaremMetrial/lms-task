<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Payouts\PayoutItemStatus;
use App\Jobs\Payouts\ReconcilePayoutItem;
use App\Models\PayoutItem;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Finds payouts whose outcome we never learned, and asks the provider.
 *
 * Two populations:
 *
 *   • 'submitted' beyond the staleness window — a worker was killed between
 *     recording intent and recording the answer. We know an attempt may be in
 *     flight because the intent was committed first. These are aged into 'unknown'.
 *
 *   • 'unknown' with next_check_at due — already identified, waiting on backoff.
 *
 * Note what this command does NOT do: it never resends. Every item here is resolved
 * by a read. That is the whole reason it is safe to run on a schedule, by hand, and
 * concurrently with itself.
 */
final class ReconcilePayouts extends Command
{
    protected $signature = 'payouts:reconcile
        {--limit=500 : Maximum items to sweep in one pass.}
        {--sync : Resolve inline instead of queueing, for demos.}';

    protected $description = 'Resolve payouts with an unknown outcome by asking the provider for their status.';

    public function handle(): int
    {
        $staleAfter = (int) config('ledger.payout.stale_submitted_after_seconds', 900);
        $limit = (int) $this->option('limit');
        $sync = (bool) $this->option('sync');
        $now = CarbonImmutable::now();

        $stale = PayoutItem::query()
            ->where('status', PayoutItemStatus::Submitted)
            ->where('submitted_at', '<=', $now->subSeconds($staleAfter))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $due = PayoutItem::query()
            ->where('status', PayoutItemStatus::Unknown)
            ->where(function ($q) use ($now): void {
                $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', $now);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $this->components->twoColumnDetail('Stale submitted (no answer received)', (string) $stale->count());
        $this->components->twoColumnDetail('Unknown and due for a check', (string) $due->count());

        $items = $stale->concat($due)->unique('id');

        foreach ($items as $item) {
            $sync
                ? ReconcilePayoutItem::dispatchSync($item->id)
                : ReconcilePayoutItem::dispatch($item->id);
        }

        $this->components->info(
            $items->isEmpty()
                ? 'Nothing to reconcile — every payout has a definitive outcome.'
                : "Queued {$items->count()} status checks. No payment is ever re-sent by this command."
        );

        return self::SUCCESS;
    }
}
