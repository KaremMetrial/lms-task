<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Allocation\AllocateSubscriptionPeriod;
use App\Actions\Allocation\AllocationOutcome;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recognises one accrual period across every subscription that overlaps it.
 *
 * Safe to run twice, concurrently, or after a crash halfway through. The guarantee
 * comes from the schema, not from this loop: `allocations` has
 * UNIQUE (subscription_id, accrual_period), and the INSERT is the claim. A second
 * run reports `already_allocated` and writes nothing.
 *
 * ── Why keyset pagination ────────────────────────────────────────────────────
 *
 * `WHERE id > ? ORDER BY id LIMIT n`, never OFFSET. At 500,000 subscriptions an
 * OFFSET scan re-reads every earlier row on every page, so the job gets slower the
 * further it gets — the classic way a batch that worked on 10k rows dies at 10M.
 *
 * ── Why the counts are reported separately ───────────────────────────────────
 *
 * `allocated` and `already_allocated` are never added together. "We processed
 * 500,000 allocations" when 499,000 were no-ops is the kind of reporting that hides
 * a scheduling bug for months.
 *
 * ── Scaling: shards, not micro-optimisation ──────────────────────────────────
 *
 * Measured throughput is ~42 subscriptions/second in a single process. Profiling put
 * the read side at 1.7 ms and the rest in the ~15 round trips an atomic allocation
 * needs — one transaction, the allocation row, its lines, a ledger entry and a
 * balance movement per instructor. That is not waste to be shaved; it is the cost of
 * each allocation committing or not committing as a unit.
 *
 * The work is embarrassingly parallel, and the schema already makes concurrency safe:
 * UNIQUE (subscription_id, accrual_period) means two shards that overlap cannot
 * double-allocate, they simply report `already_allocated`. So volume is answered by
 * running more processes:
 *
 *   for i in $(seq 0 7); do
 *     php artisan ledger:accrue --period=2026-06 --shard=$i/8 &
 *   done; wait
 *
 * Sharding on `id % N` keeps the SUBSCRIPTION partitions disjoint — but shards still
 * contend, because different subscriptions share instructors and therefore share
 * instructor_balances rows. The first parallel run lost 2 of 6 shards to
 * SQLSTATE 40001 deadlocks.
 *
 * That is handled by retrying the transaction (AllocateSubscriptionPeriod uses
 * `attempts: 3`), which is safe only because the transaction is idempotent: the unique
 * indexes mean a retry writes nothing twice. The property built for crash recovery
 * turned out to answer lock contention too.
 */
final class AccrueRevenue extends Command
{
    protected $signature = 'ledger:accrue
        {--period= : Accrual period as YYYY-MM. Defaults to last month.}
        {--chunk=500 : Subscriptions per page.}
        {--limit= : Stop after this many subscriptions, for smoke tests.}
        {--shard= : Process only one shard, as i/N (e.g. 0/8). Run N processes in parallel.}';

    protected $description = 'Recognise one accrual period and turn it into instructor earnings.';

    public function handle(AllocateSubscriptionPeriod $allocate): int
    {
        $period = $this->resolvePeriod();

        if ($period === null) {
            $this->error('The --period option must look like YYYY-MM.');

            return self::FAILURE;
        }

        [$periodStart, $periodEnd] = $this->boundsOf($period);

        $this->components->info("Accruing {$period}");

        $counts = [
            AllocationOutcome::Allocated->value => 0,
            AllocationOutcome::AlreadyAllocated->value => 0,
            AllocationOutcome::Skipped->value => 0,
        ];

        $shard = $this->resolveShard();

        if ($shard === false) {
            $this->error('The --shard option must look like i/N, with 0 <= i < N.');

            return self::FAILURE;
        }

        if ($shard !== null) {
            $this->components->twoColumnDetail('Shard', "{$shard[0]} of {$shard[1]}");
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $cursor = 0;
        $seen = 0;
        $started = microtime(true);

        $total = $this->countCandidates($periodStart, $periodEnd, $limit, $shard);
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        while (true) {
            $subscriptions = Subscription::query()
                // Only subscriptions whose term actually overlaps this period. Without
                // this the job would walk every subscription ever sold for each month.
                ->where('starts_on', '<=', $periodEnd)
                ->where('ends_on', '>=', $periodStart)
                ->where('id', '>', $cursor)
                ->when($shard !== null, fn ($q) => $q->whereRaw('id % ? = ?', [$shard[1], $shard[0]]))
                ->orderBy('id')
                ->limit($chunk)
                ->get();

            if ($subscriptions->isEmpty()) {
                break;
            }

            foreach ($subscriptions as $subscription) {
                $cursor = $subscription->id;

                $result = $allocate->execute($subscription, $period);
                $counts[$result->outcome->value]++;

                $seen++;
                $bar->advance();

                if ($limit !== null && $seen >= $limit) {
                    break 2;
                }
            }
        }

        $bar->finish();
        $this->newLine(2);

        $elapsed = microtime(true) - $started;

        $this->components->twoColumnDetail('Allocated (real work)', (string) $counts['allocated']);
        $this->components->twoColumnDetail('Already allocated (replay)', (string) $counts['already_allocated']);
        $this->components->twoColumnDetail('Skipped (outside term)', (string) $counts['skipped']);
        $this->components->twoColumnDetail('Elapsed', sprintf('%.1fs', $elapsed));

        if ($seen > 0) {
            $this->components->twoColumnDetail(
                'Throughput',
                sprintf('%.0f subscriptions/sec', $seen / max($elapsed, 0.001)),
            );
        }

        return self::SUCCESS;
    }

    /** @param array{0: int, 1: int}|null $shard */
    private function countCandidates(string $periodStart, string $periodEnd, ?int $limit, ?array $shard): int
    {
        $count = (int) DB::table('subscriptions')
            ->where('starts_on', '<=', $periodEnd)
            ->where('ends_on', '>=', $periodStart)
            ->when($shard !== null, fn ($q) => $q->whereRaw('id % ? = ?', [$shard[1], $shard[0]]))
            ->count();

        return $limit === null ? $count : min($count, $limit);
    }

    /**
     * @return array{0: int, 1: int}|null|false null = no sharding, false = malformed
     */
    private function resolveShard(): array|null|false
    {
        $shard = $this->option('shard');

        if ($shard === null) {
            return null;
        }

        if (preg_match('/^(\d+)\/(\d+)$/', (string) $shard, $m) !== 1) {
            return false;
        }

        [$index, $of] = [(int) $m[1], (int) $m[2]];

        return ($of > 0 && $index >= 0 && $index < $of) ? [$index, $of] : false;
    }

    /** @return array{0: string, 1: string} */
    private function boundsOf(string $period): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $period.'-01');

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }

    private function resolvePeriod(): ?string
    {
        $period = $this->option('period');

        if ($period === null) {
            // Last month: a period is only fully accruable once it has ended.
            return now()->subMonthNoOverflow()->format('Y-m');
        }

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $period) === 1
            ? (string) $period
            : null;
    }
}
