<?php

declare(strict_types=1);

namespace App\Actions\Allocation;

use App\Domain\Allocation\AllocationStatus;
use App\Domain\Allocation\AllocationStrategyResolver;
use App\Domain\Allocation\EngagementWindow;
use App\Domain\Ledger\LedgerEntryType;
use App\Domain\Ledger\LedgerWriter;
use App\Domain\Money\Money;
use App\Domain\Recognition\AccrualPeriod;
use App\Domain\Recognition\RecognitionSchedule;
use App\Models\Allocation;
use App\Models\Payment;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Turns one accrual period of one subscription into instructor earnings.
 *
 * ── The pipeline ─────────────────────────────────────────────────────────────
 *
 *   1. Recognise  — how much of the prepaid term belongs to this month
 *   2. Cut        — platform share off the top, at the rate SNAPSHOTTED on the
 *                   subscription, never today's config
 *   3. Attribute  — who was engaged this month, and in what proportion
 *   4. Split      — Money::allocate(), the only divider of money in the codebase
 *   5. Record     — allocation + lines + ledger entries + balances, one transaction
 *
 * ── Idempotency ──────────────────────────────────────────────────────────────
 *
 * Running this twice for the same (subscription, period) is a no-op, guaranteed at
 * two independent layers:
 *
 *   • allocations has UNIQUE (subscription_id, accrual_period). The INSERT itself
 *     is the claim — there is no check-then-write window for a concurrent worker
 *     to slip into.
 *   • ledger_entries has UNIQUE (type, source_type, source_id), so even a
 *     hand-crafted replay that got past the first layer cannot move a balance.
 *
 * The result object says which happened, so callers can report accurately instead
 * of assuming.
 */
final readonly class AllocateSubscriptionPeriod
{
    public function __construct(
        private AllocationStrategyResolver $strategies,
        private LedgerWriter $ledger,
        private ?ConnectionInterface $connection = null,
    ) {}

    private function db(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }

    public function execute(Subscription $subscription, string $accrualPeriod): AllocationResult
    {
        $payment = $subscription->payments()->orderBy('id')->first();

        if ($payment === null) {
            return AllocationResult::skipped(
                $accrualPeriod,
                'Subscription has no payment; nothing has been received to recognise.'
            );
        }

        $period = $this->periodFor($subscription, $accrualPeriod);

        if ($period === null) {
            return AllocationResult::skipped(
                $accrualPeriod,
                'Period falls outside the recognised term (not started, ended, or cancelled before it).'
            );
        }

        if ($period->gross->isZero()) {
            return AllocationResult::skipped($accrualPeriod, 'Nothing recognised for this period.');
        }

        // Step 2. The rate comes off the subscription row, not config — so changing
        // the platform's cut tomorrow cannot rewrite what was earned last year.
        $platformShare = $period->gross->shareOfBps($subscription->platform_share_bps);
        $pool = $period->gross->minus($platformShare);

        // Step 3 and 4.
        $strategy = $this->strategies->active();
        $window = $this->engagementWindow($subscription, $accrualPeriod);
        $weights = $strategy->weights($window);

        // No engaged instructors means the whole period is platform revenue. That
        // is a real outcome, recorded rather than skipped, so the money is still
        // accounted for and the period is not re-processed on the next run.
        $lines = $weights === [] ? [] : $pool->allocate($weights);
        $distributed = array_reduce(
            $lines,
            fn (Money $carry, Money $line): Money => $carry->plus($line),
            Money::zero($pool->currency),
        );

        // Whatever no instructor claimed stays with the platform, so gross always
        // reconciles even when nobody was engaged.
        $platformTotal = $period->gross->minus($distributed);

        return $this->persist(
            subscription: $subscription,
            payment: $payment,
            period: $period,
            platformTotal: $platformTotal,
            distributed: $distributed,
            lines: $lines,
            weights: $weights,
            strategyName: $strategy->name(),
        );
    }

    private function periodFor(Subscription $subscription, string $accrualPeriod): ?AccrualPeriod
    {
        return RecognitionSchedule::build(
            total: $subscription->amount_minor,
            termStartsOn: $subscription->starts_on,
            termEndsOn: $subscription->ends_on,
            recognitionEndsOn: $subscription->recognitionEndsOn(),
        )->findPeriod($accrualPeriod);
    }

    private function engagementWindow(Subscription $subscription, string $accrualPeriod): EngagementWindow
    {
        // Narrow projection on purpose: this runs across tens of millions of rows,
        // and hydrating Eloquent models to read three integers would dominate the cost.
        $rows = $this->db()->table('engagements')
            ->select('instructor_id', 'course_id', 'watched_seconds', 'sessions_count')
            ->where('student_id', $subscription->student_id)
            ->where('accrual_period', $accrualPeriod)
            ->orderBy('instructor_id')
            ->get();

        return EngagementWindow::fromRows($rows);
    }

    /**
     * @param  array<int, Money>  $lines
     * @param  array<int, int>  $weights
     */
    private function persist(
        Subscription $subscription,
        Payment $payment,
        AccrualPeriod $period,
        Money $platformTotal,
        Money $distributed,
        array $lines,
        array $weights,
        string $strategyName,
    ): AllocationResult {
        // Belt and braces: the invariant is proven exhaustively in unit tests, but a
        // future refactor could break the assembly above. Money must never leave
        // this method unbalanced, so it is checked before anything is written.
        if (! $platformTotal->plus($distributed)->equals($period->gross)) {
            throw new \LogicException(sprintf(
                'Allocation does not balance for subscription %d period %s: %d platform + %d '
                .'instructors != %d gross.',
                $subscription->id,
                $period->key,
                $platformTotal->minor,
                $distributed->minor,
                $period->gross->minor,
            ));
        }

        $weightTotal = array_sum($weights);
        $now = CarbonImmutable::now();

        try {
            // attempts: 3 — retry on deadlock.
            //
            // Concurrent accrual shards partition SUBSCRIPTIONS, but different
            // subscriptions share INSTRUCTORS, so they contend on instructor_balances.
            // Measured: 2 of 6 parallel shards died on SQLSTATE 40001 before this.
            //
            // A blind retry is only safe because this transaction is idempotent — the
            // unique indexes on allocations and ledger_entries mean a replay writes
            // nothing. That property, built for crash recovery, pays for itself again
            // here: deadlock handling costs one argument instead of a design.
            return $this->db()->transaction(function () use (
                $subscription, $payment, $period, $platformTotal, $distributed,
                $lines, $weights, $weightTotal, $strategyName, $now
            ): AllocationResult {
                // The INSERT is the claim. If a concurrent worker already allocated
                // this period, the unique index rejects this and we fall through to
                // the catch below — no check-then-write window.
                $allocation = Allocation::query()->create([
                    'subscription_id' => $subscription->id,
                    'payment_id' => $payment->id,
                    'accrual_period' => $period->key,
                    'period_starts_on' => $period->startsOn,
                    'period_ends_on' => $period->endsOn,
                    'days_in_period' => $period->days,
                    'gross_minor' => $period->gross,
                    'platform_minor' => $platformTotal,
                    'instructor_pool_minor' => $distributed,
                    'currency' => $period->gross->currency,
                    'strategy' => $strategyName,
                    'status' => AllocationStatus::Allocated,
                    'allocated_at' => $now,
                ]);

                foreach ($lines as $instructorId => $amount) {
                    $line = $allocation->lines()->create([
                        'instructor_id' => $instructorId,
                        'amount_minor' => $amount,
                        'currency' => $amount->currency,
                        'weight' => $weights[$instructorId],
                        'weight_total' => $weightTotal,
                    ]);

                    // A zero share still gets a line — the instructor was engaged and
                    // deserves the record — but writing a zero-value ledger entry
                    // would be noise, so it is skipped.
                    if ($amount->isZero()) {
                        continue;
                    }

                    $this->ledger->append(
                        instructorId: (int) $instructorId,
                        type: LedgerEntryType::Earning,
                        amount: $amount,
                        sourceType: 'allocation_line',
                        sourceId: (int) $line->id,

                        // Business time, not wall-clock: a September earning written
                        // during the October run occurred in September.
                        occurredAt: $period->endsOn->endOfDay(),
                    );
                }

                return AllocationResult::allocated($allocation);
            }, attempts: 3);
        } catch (QueryException $e) {
            if (! $this->isDuplicateKey($e)) {
                throw $e;
            }

            // Somebody else got there first. That is a correct outcome, not an error:
            // exactly one allocation exists for this period, which is the guarantee.
            $existing = Allocation::query()
                ->where('subscription_id', $subscription->id)
                ->where('accrual_period', $period->key)
                ->firstOrFail();

            return AllocationResult::alreadyAllocated($existing);
        }
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        // MySQL 1062 / SQLSTATE 23000.
        return ($e->errorInfo[1] ?? null) === 1062 || $e->getCode() === '23000';
    }
}
