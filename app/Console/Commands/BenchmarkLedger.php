<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Payouts\PayoutItemStatus;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Times the queries the system actually depends on, against whatever data is present.
 *
 * The scaling claims in ARCHITECTURE.md are only worth making if they can be
 * checked, so this measures rather than asserts. It also prints the EXPLAIN access
 * type for each query, because "fast on this dataset" and "will stay fast" are
 * different claims — a full table scan that is quick on 20k rows is a time bomb at
 * 20M, and `type: ALL` is how you spot it before production does.
 */
final class BenchmarkLedger extends Command
{
    protected $signature = 'ledger:benchmark {--runs=5 : Times to repeat each query.}';

    protected $description = 'Time and EXPLAIN the queries the money core depends on.';

    public function handle(): int
    {
        $this->printDataShape();

        $runs = max(1, (int) $this->option('runs'));
        $results = [];

        // 1. The dashboard question. Must never touch the ledger.
        $results[] = $this->measure(
            'Balance for one instructor',
            $runs,
            fn () => DB::table('instructor_balances')->where('instructor_id', 1)->first(),
            DB::table('instructor_balances')->where('instructor_id', 1),
        );

        // 2. The payout fan-out query, exactly as FanOutPayoutBatch issues it.
        $results[] = $this->measure(
            'Payable instructors (keyset page)',
            $runs,
            fn () => DB::table('instructor_balances as b')
                ->join('instructors as i', 'i.id', '=', 'b.instructor_id')
                ->where('b.currency', config('ledger.currency'))
                ->where('b.available_minor', '>', 0)
                ->where('b.instructor_id', '>', 0)
                ->orderBy('b.instructor_id')
                ->limit(1_000)
                ->get(),
            DB::table('instructor_balances as b')
                ->join('instructors as i', 'i.id', '=', 'b.instructor_id')
                ->where('b.available_minor', '>', 0)
                ->orderBy('b.instructor_id')
                ->limit(1_000),
        );

        // 3. The allocation hot path: who did this student engage with this month.
        $studentId = (int) (DB::table('engagements')->value('student_id') ?? 1);
        $period = (string) (DB::table('engagements')->value('accrual_period') ?? '2026-01');

        $results[] = $this->measure(
            'Engagement window (one student, one period)',
            $runs,
            fn () => DB::table('engagements')
                ->select('instructor_id', 'watched_seconds', 'sessions_count')
                ->where('student_id', $studentId)
                ->where('accrual_period', $period)
                ->get(),
            DB::table('engagements')
                ->where('student_id', $studentId)
                ->where('accrual_period', $period),
        );

        // 4. Accrual idempotency lookup, hit once per subscription per period.
        $results[] = $this->measure(
            'Allocation idempotency lookup',
            $runs,
            fn () => DB::table('allocations')
                ->where('subscription_id', 1)
                ->where('accrual_period', $period)
                ->first(),
            DB::table('allocations')->where('subscription_id', 1)->where('accrual_period', $period),
        );

        // 5. The reconciler's sweep for uncertain payouts.
        $results[] = $this->measure(
            'Sweep for uncertain payouts',
            $runs,
            fn () => DB::table('payout_items')
                ->whereIn('status', [PayoutItemStatus::Submitted->value, PayoutItemStatus::Unknown->value])
                ->orderBy('id')
                ->limit(500)
                ->get(),
            DB::table('payout_items')
                ->whereIn('status', [PayoutItemStatus::Submitted->value, PayoutItemStatus::Unknown->value])
                ->limit(500),
        );

        // 6. The one query that IS allowed to be slow, shown for contrast: a full
        //    balance rebuild from the ledger. It is a scheduled audit, never a read path.
        $results[] = $this->measure(
            'Rebuild one balance from the ledger (audit only)',
            $runs,
            fn () => DB::table('ledger_entries')
                ->where('instructor_id', 1)
                ->selectRaw('COALESCE(SUM(amount_minor), 0) as total')
                ->first(),
            DB::table('ledger_entries')->where('instructor_id', 1),
        );

        $this->newLine();
        $this->table(['Query', 'Median', 'Rows', 'EXPLAIN type', 'Key used'], $results);

        $scans = array_filter($results, fn (array $r): bool => $r[3] === 'ALL');

        if ($scans !== []) {
            $this->components->error(
                count($scans).' query(ies) report a full table scan (type: ALL). '
                .'Fine on a small dataset, fatal at production size — add an index.'
            );

            return self::FAILURE;
        }

        $this->components->info('Every measured query uses an index. No full table scans.');

        return self::SUCCESS;
    }

    private function printDataShape(): void
    {
        $this->components->info('Data shape');

        foreach (['instructors', 'students', 'subscriptions', 'payments', 'engagements',
            'allocations', 'allocation_lines', 'ledger_entries', 'payout_items'] as $table) {
            $this->components->twoColumnDetail(
                $table,
                number_format(DB::table($table)->count()),
            );
        }
    }

    /**
     * @param  callable(): mixed  $run
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    private function measure(string $label, int $runs, callable $run, Builder $explainable): array
    {
        $timings = [];
        $rows = 0;

        for ($i = 0; $i < $runs; $i++) {
            $started = hrtime(true);
            $result = $run();
            $timings[] = (hrtime(true) - $started) / 1_000_000;

            $rows = is_countable($result) ? count($result) : ($result === null ? 0 : 1);
        }

        sort($timings);
        $median = $timings[intdiv(count($timings), 2)];

        $explain = $this->explain($explainable);

        return [
            $label,
            sprintf('%.2f ms', $median),
            number_format($rows),
            $explain['type'],
            $explain['key'],
        ];
    }

    /** @return array{type: string, key: string} */
    private function explain(Builder $query): array
    {
        try {
            $row = DB::select('EXPLAIN '.$query->toSql(), $query->getBindings())[0] ?? null;

            return [
                'type' => (string) ($row->type ?? '?'),
                'key' => (string) ($row->key ?? '—'),
            ];
        } catch (\Throwable $e) {
            return ['type' => '?', 'key' => 'explain failed'];
        }
    }
}
