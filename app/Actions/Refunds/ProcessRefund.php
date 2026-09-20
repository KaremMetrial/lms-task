<?php

declare(strict_types=1);

namespace App\Actions\Refunds;

use App\Domain\Allocation\AllocationStatus;
use App\Domain\Ledger\LedgerEntryType;
use App\Domain\Ledger\LedgerWriter;
use App\Domain\Money\Money;
use App\Domain\Recognition\RecognitionSchedule;
use App\Domain\Refunds\RefundKind;
use App\Domain\Subscriptions\SubscriptionStatus;
use App\Models\Allocation;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Refunds a student and corrects the ledger.
 *
 * ── Why pro-rata refunds are boring, and that is the point ────────────────────
 *
 * Revenue is recognised ratably (see RecognitionSchedule). So on the day a student
 * leaves, the unconsumed part of the term was never recognised — which means it was
 * never payable, which means it was never paid. The student gets it back and
 * **no instructor balance moves at all.**
 *
 * That is not clever refund code. It is the consequence of choosing the right
 * recognition rule, and it is why this class is mostly bookkeeping.
 *
 * ── Corrections are new entries, never edits ──────────────────────────────────
 *
 * When a period DOES have to be undone — an allocation that ran ahead of a
 * retroactive cancellation, or the partial month the student left in — the original
 * allocation row is left exactly as it was and a `reversal` entry is written against
 * it. Editing the original would destroy the audit trail and make the historical
 * split unreproducible. The allocation records what was allocated; the ledger
 * records what is owed. Those are different questions.
 *
 * ── Idempotency ──────────────────────────────────────────────────────────────
 *
 * Refund webhooks get delivered twice. Two independent guards:
 *
 *   • `refunds.idempotency_key` is UNIQUE, and the INSERT is the claim.
 *   • Every correcting entry is keyed (type, allocation_line, line_id), so even a
 *     replay that got past the first guard cannot move a balance twice.
 *
 * ── reversal vs clawback ─────────────────────────────────────────────────────
 *
 * Both reduce what is owed; the distinction is intent, and it is preserved because
 * reports read very differently:
 *
 *   reversal — a pro-rata correction. Ordinary bookkeeping.
 *   clawback — a full refund (fraud, chargeback) recovering money the instructor
 *              may already have been paid. Can drive a balance negative, which is
 *              then netted against future earnings, never collected by demanding
 *              money back.
 */
final readonly class ProcessRefund
{
    public function __construct(
        private LedgerWriter $ledger,
        private ?ConnectionInterface $connection = null,
    ) {}

    private function db(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }

    public function execute(
        Subscription $subscription,
        RefundKind $kind,
        CarbonImmutable $effectiveOn,
        string $reason,
        string $idempotencyKey,
    ): RefundResult {
        $payment = $subscription->payments()->orderBy('id')->firstOrFail();

        $existing = Refund::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return RefundResult::alreadyProcessed($existing);
        }

        try {
            return $this->db()->transaction(
                fn (): RefundResult => $kind === RefundKind::Full
                    ? $this->processFull($subscription, $payment, $effectiveOn, $reason, $idempotencyKey)
                    : $this->processProrata($subscription, $payment, $effectiveOn, $reason, $idempotencyKey)
            );
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062 && $e->getCode() !== '23000') {
                throw $e;
            }

            // A concurrent delivery of the same webhook won. Correct outcome.
            return RefundResult::alreadyProcessed(
                Refund::query()->where('idempotency_key', $idempotencyKey)->firstOrFail()
            );
        }
    }

    // ── Pro-rata: the normal path ───────────────────────────────────────────

    private function processProrata(
        Subscription $subscription,
        Payment $payment,
        CarbonImmutable $effectiveOn,
        string $reason,
        string $idempotencyKey,
    ): RefundResult {
        // Recognition stops here. Everything after this date is unearned by
        // definition, which is what makes it refundable without a clawback.
        $subscription->update([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_on' => $effectiveOn,
        ]);

        $schedule = RecognitionSchedule::build(
            total: $subscription->amount_minor,
            termStartsOn: $subscription->starts_on,
            termEndsOn: $subscription->ends_on,
            recognitionEndsOn: $effectiveOn,
        );

        $refundAmount = $schedule->unearnedTotal();

        $reclaimed = Money::zero($subscription->currency);
        $voided = 0;
        $adjusted = 0;

        foreach ($this->allocationsOf($subscription) as $allocation) {
            $period = $schedule->findPeriod($allocation->accrual_period);

            // No period means this month fell outside the shortened term entirely, so
            // nothing is recognised for it any more.
            $stillRecognised = $period === null
                ? Money::zero($allocation->currency)
                : $period->gross;

            $overRecognised = $allocation->gross_minor->minus($stillRecognised);

            if (! $overRecognised->isPositive()) {
                continue;
            }

            if ($stillRecognised->isZero()) {
                // The whole period is gone — allocated ahead of a cancellation that
                // turned out to be retroactive.
                $reclaimed = $reclaimed->plus(
                    $this->reverseEntirely($allocation, LedgerEntryType::Reversal, $reason)
                );
                $voided++;

                continue;
            }

            // The partial month the student left in. The over-recognised slice is
            // split across the original lines in their original proportions, using
            // the same allocator — so it is exact, and an instructor's correction is
            // proportional to what they were credited.
            $reclaimed = $reclaimed->plus(
                $this->reversePartially($allocation, $overRecognised, $reason)
            );
            $adjusted++;
        }

        $refund = Refund::query()->create([
            'subscription_id' => $subscription->id,
            'payment_id' => $payment->id,
            'amount_minor' => $refundAmount,
            'currency' => $subscription->currency,
            'kind' => RefundKind::Prorata,
            'reason' => $reason,
            'refunded_at' => CarbonImmutable::now(),
            'idempotency_key' => $idempotencyKey,
        ]);

        return RefundResult::processed($refund, $refundAmount, $reclaimed, $voided, $adjusted);
    }

    // ── Full: the exception ─────────────────────────────────────────────────

    private function processFull(
        Subscription $subscription,
        Payment $payment,
        CarbonImmutable $effectiveOn,
        string $reason,
        string $idempotencyKey,
    ): RefundResult {
        $subscription->update([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_on' => $subscription->starts_on,
        ]);

        $reclaimed = Money::zero($subscription->currency);
        $voided = 0;

        foreach ($this->allocationsOf($subscription) as $allocation) {
            // Clawback, not reversal: this money may already have been paid out, and
            // the type keeps that distinction visible in every report.
            $reclaimed = $reclaimed->plus(
                $this->reverseEntirely($allocation, LedgerEntryType::Clawback, $reason)
            );
            $voided++;
        }

        $refund = Refund::query()->create([
            'subscription_id' => $subscription->id,
            'payment_id' => $payment->id,
            'amount_minor' => $subscription->amount_minor,
            'currency' => $subscription->currency,
            'kind' => RefundKind::Full,
            'reason' => $reason,
            'refunded_at' => CarbonImmutable::now(),
            'idempotency_key' => $idempotencyKey,
        ]);

        return RefundResult::processed(
            $refund,
            $subscription->amount_minor,
            $reclaimed,
            $voided,
            0,
        );
    }

    // ── Mechanics ───────────────────────────────────────────────────────────

    /** @return Collection<int, Allocation> */
    private function allocationsOf(Subscription $subscription)
    {
        return Allocation::query()
            ->with('lines')
            ->where('subscription_id', $subscription->id)
            ->where('status', AllocationStatus::Allocated)
            // Serialise against a concurrent allocation run for the same subscription.
            ->lockForUpdate()
            ->orderBy('accrual_period')
            ->get();
    }

    private function reverseEntirely(Allocation $allocation, LedgerEntryType $type, string $reason): Money
    {
        $reclaimed = Money::zero($allocation->currency);

        foreach ($allocation->lines as $line) {
            if ($line->amount_minor->isZero()) {
                continue;
            }

            $written = $this->ledger->append(
                instructorId: $line->instructor_id,
                type: $type,
                amount: $line->amount_minor->negated(),
                sourceType: 'allocation_line',
                sourceId: $line->id,
                occurredAt: CarbonImmutable::now(),
                reason: $reason,
            );

            // false means it was already reversed — a replay. Not counted twice.
            if ($written) {
                $reclaimed = $reclaimed->plus($line->amount_minor);
            }
        }

        $allocation->update([
            'status' => AllocationStatus::Voided,
            'voided_at' => CarbonImmutable::now(),
        ]);

        return $reclaimed;
    }

    private function reversePartially(Allocation $allocation, Money $overRecognised, string $reason): Money
    {
        // The instructors' share of the slice being taken back, computed at the rate
        // snapshotted on the allocation rather than from config.
        $instructorShare = $allocation->instructor_pool_minor->isZero()
            ? Money::zero($allocation->currency)
            : $overRecognised->shareOfBps($this->instructorBps($allocation));

        if ($instructorShare->isZero()) {
            return Money::zero($allocation->currency);
        }

        /** @var array<int, int> $weights */
        $weights = [];

        foreach ($allocation->lines as $line) {
            $weights[$line->instructor_id] = $line->amount_minor->minor;
        }

        if ($weights === [] || array_sum($weights) === 0) {
            return Money::zero($allocation->currency);
        }

        // Same allocator as the original split, so the correction is exact and the
        // remainder rule is identical. Reusing it is the reason a partial reversal
        // cannot drift by a piastre.
        $perInstructor = $instructorShare->allocate($weights);

        $reclaimed = Money::zero($allocation->currency);
        $linesById = $allocation->lines->keyBy('instructor_id');

        foreach ($perInstructor as $instructorId => $amount) {
            if ($amount->isZero()) {
                continue;
            }

            $written = $this->ledger->append(
                instructorId: (int) $instructorId,
                type: LedgerEntryType::Reversal,
                amount: $amount->negated(),
                sourceType: 'allocation_line',
                sourceId: (int) $linesById[$instructorId]->id,
                occurredAt: CarbonImmutable::now(),
                reason: $reason,
            );

            if ($written) {
                $reclaimed = $reclaimed->plus($amount);
            }
        }

        return $reclaimed;
    }

    /**
     * The instructors' share of this allocation, in basis points, derived from what
     * was actually recorded.
     *
     * Deliberately not read from config or from the subscription: if the allocation
     * put 70% into the pool, the correction must use 70%, whatever the rate has since
     * become.
     */
    private function instructorBps(Allocation $allocation): int
    {
        if ($allocation->gross_minor->isZero()) {
            return 0;
        }

        return intdiv($allocation->instructor_pool_minor->minor * 10_000, $allocation->gross_minor->minor);
    }
}
