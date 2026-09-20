<?php

declare(strict_types=1);

namespace App\Actions\Payouts;

use App\Domain\Payouts\PayoutItemStatus;
use App\Models\PayoutBatch;
use App\Models\PayoutItem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Creates one payout item per payable instructor for a batch.
 *
 * ── Replayable by construction ───────────────────────────────────────────────
 *
 * `payout_items` has UNIQUE (payout_batch_id, instructor_id), and this writes with
 * insertOrIgnore. So running the fan-out again — a crashed command re-run, a manual
 * re-trigger, two servers at once — produces zero new rows. It does not need to
 * know whether it has run before.
 *
 * ── Why the amount is snapshotted ────────────────────────────────────────────
 *
 * The item stores the amount as it stood when the batch was fanned out. If the
 * instructor earns more tomorrow, this batch still pays what it claimed, and the
 * new earnings go to the next batch. Recomputing at send time would make the amount
 * depend on when a worker happened to pick the job up — unreproducible, and
 * impossible to reconcile against.
 *
 * ── Scaling ──────────────────────────────────────────────────────────────────
 *
 * Keyset pagination (`WHERE instructor_id > ?`), never OFFSET, which degrades
 * linearly and is the standard way a batch job that worked at 10k rows dies at 10M.
 */
final readonly class FanOutPayoutBatch
{
    private const CHUNK = 1_000;

    public function __construct(private ?ConnectionInterface $connection = null) {}

    private function db(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }

    public function execute(PayoutBatch $batch): FanOutSummary
    {
        $minimum = $batch->minimum_payout_minor->minor;
        $created = 0;
        $skippedBelowMinimum = 0;
        $skippedNotPayable = 0;
        $cursor = 0;

        while (true) {
            $rows = $this->db()->table('instructor_balances as b')
                ->join('instructors as i', 'i.id', '=', 'b.instructor_id')
                ->select(
                    'b.instructor_id',
                    'b.available_minor',
                    'b.currency',
                    'i.status',
                    'i.payout_account_ref',
                )
                ->where('b.currency', $batch->currency)
                ->where('b.available_minor', '>', 0)
                ->where('b.instructor_id', '>', $cursor)
                ->orderBy('b.instructor_id')
                ->limit(self::CHUNK)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            $insert = [];

            foreach ($rows as $row) {
                $cursor = (int) $row->instructor_id;

                // Dust payouts destroy value for both sides once provider fees are
                // counted. The money stays visible as outstanding and joins the next run.
                if ($row->available_minor < $minimum) {
                    $skippedBelowMinimum++;

                    continue;
                }

                // Earnings accrue for a suspended or un-onboarded instructor; payouts
                // wait. Nothing is lost, it is simply not sent yet.
                if ($row->status !== 'active' || $row->payout_account_ref === null) {
                    $skippedNotPayable++;

                    continue;
                }

                $insert[] = [
                    'payout_batch_id' => $batch->id,
                    'instructor_id' => (int) $row->instructor_id,
                    'amount_minor' => (int) $row->available_minor,
                    'currency' => (string) $row->currency,
                    'status' => PayoutItemStatus::Pending->value,
                    'idempotency_key' => PayoutItem::idempotencyKeyFor($batch->id, (int) $row->instructor_id),
                    'attempts' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($insert !== []) {
                // insertOrIgnore: a replay collides with the unique index and is
                // silently skipped, so the count reflects real work only.
                $created += $this->db()->table('payout_items')->insertOrIgnore($insert);
            }
        }

        $this->refreshTotals($batch);

        return new FanOutSummary(
            itemsCreated: $created,
            skippedBelowMinimum: $skippedBelowMinimum,
            skippedNotPayable: $skippedNotPayable,
            totalItems: (int) $batch->items_count,
        );
    }

    private function refreshTotals(PayoutBatch $batch): void
    {
        $totals = $this->db()->table('payout_items')
            ->where('payout_batch_id', $batch->id)
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(amount_minor), 0) as s')
            ->first();

        $batch->update([
            'items_count' => (int) ($totals->c ?? 0),
            'total_minor' => (int) ($totals->s ?? 0),
        ]);
    }
}
