<?php

declare(strict_types=1);

namespace App\Actions\Payouts;

use App\Domain\Ledger\InstructorBalanceRepository;
use App\Domain\Ledger\LedgerEntryType;
use App\Domain\Ledger\LedgerWriter;
use App\Domain\Payouts\FailureClass;
use App\Domain\Payouts\PayoutItemStatus;
use App\Models\PayoutItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Every state change a payout item can undergo, and the only place they happen.
 *
 * ── The mechanism ────────────────────────────────────────────────────────────
 *
 * Each transition is a single conditional UPDATE:
 *
 *     UPDATE payout_items SET status = <next> WHERE id = ? AND status = <expected>
 *
 * If it affects zero rows, somebody else already moved this item and this caller
 * returns false WITHOUT acting. That is compare-and-swap in the database: no lock,
 * no coordination, and correct under any number of concurrent workers.
 *
 * It is also what makes a retried job harmless. A worker killed after submitting
 * comes back, finds the item is no longer 'pending', and stops — rather than
 * sending a second payment.
 *
 * ── Where the money is ───────────────────────────────────────────────────────
 *
 *   pending    amount is in `available`  — payable, nothing sent
 *   submitted  amount is in `reserved`   — not payable, not paid, in flight
 *   unknown    amount is in `reserved`   — still held, outcome not yet known
 *   succeeded  amount is in `paid`       — gone
 *   failed     amount is back in `available`
 *
 * `reserved` is the column that makes a second payout run safe while a first
 * attempt is still uncertain: available = earned - paid - reserved simply cannot
 * offer money that might already be in flight.
 */
final readonly class PayoutItemTransitions
{
    public function __construct(
        private LedgerWriter $ledger,
        private InstructorBalanceRepository $balances,
        private ?ConnectionInterface $connection = null,
    ) {}

    private function db(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }

    /**
     * Claim the item for sending and place the hold.
     *
     * WRITE-AHEAD: this commits BEFORE the provider is called. If the process dies
     * during the call, recovery finds a 'submitted' row and knows an attempt may be
     * in flight. Nothing is ever inferred from the absence of a record.
     *
     * Returns false when the item was already claimed, or when the instructor no
     * longer has the money available (another batch got there first).
     */
    public function claimForSending(PayoutItem $item): bool
    {
        // attempts: 3 — concurrent payout workers contend on instructor_balances.
        // Safe to retry because the conditional UPDATE makes the transaction idempotent.
        return $this->db()->transaction(function () use ($item): bool {
            // Lock the balance first, then the item — a consistent ordering, so two
            // workers touching the same instructor cannot deadlock against each other.
            $balance = $this->db()->table('instructor_balances')
                ->where('instructor_id', $item->instructor_id)
                ->lockForUpdate()
                ->first();

            if ($balance === null) {
                return false;
            }

            // Re-check at send time, not just at fan-out time. Between the two, a
            // manual re-run for another period could have claimed the same money.
            if ($balance->available_minor < $item->amount_minor->minor) {
                $this->transition($item, PayoutItemStatus::Pending, PayoutItemStatus::Failed, [
                    'failure_class' => FailureClass::Permanent->value,
                    'last_error' => sprintf(
                        'Available balance %d is below the claimed amount %d; another run holds it.',
                        $balance->available_minor,
                        $item->amount_minor->minor,
                    ),
                    'settled_at' => now(),
                ]);

                return false;
            }

            $moved = $this->transition($item, PayoutItemStatus::Pending, PayoutItemStatus::Submitted, [
                'submitted_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
            ]);

            if (! $moved) {
                return false;
            }

            // The hold. Not a ledger entry: nothing has been earned or paid, so the
            // ledger has nothing to say. `reserved` is verifiable independently by
            // summing payout_items that still hold a reservation.
            $this->balances->applyDeltas(
                instructorId: $item->instructor_id,
                currency: $item->currency,
                reservedDelta: $item->amount_minor->minor,
            );

            return true;
        }, attempts: 3);
    }

    /**
     * Money confirmed sent: release the hold and record the payout.
     *
     * The ledger entry is keyed on (payout, payout_item, id), so calling this twice
     * — a duplicated confirmation, a replayed reconcile — writes one entry and moves
     * the balance once. The unique index, not this method, is the guarantee.
     */
    public function settleAsSucceeded(PayoutItem $item, string $providerReference): bool
    {
        return $this->db()->transaction(function () use ($item, $providerReference): bool {
            $moved = $this->transitionFromAny(
                $item,
                [PayoutItemStatus::Submitted, PayoutItemStatus::Unknown],
                PayoutItemStatus::Succeeded,
                [
                    'provider_reference' => $providerReference,
                    'settled_at' => now(),
                    'next_check_at' => null,
                    'failure_class' => null,
                ],
            );

            if (! $moved) {
                return false;
            }

            // Negative: a payout reduces what the instructor is owed. The type's
            // snapshotDeltas moves reserved → paid in one update, so `available`
            // never flickers between the two.
            $this->ledger->append(
                instructorId: $item->instructor_id,
                type: LedgerEntryType::Payout,
                amount: $item->amount_minor->negated(),
                sourceType: 'payout_item',
                sourceId: $item->id,
                occurredAt: CarbonImmutable::now(),
                reason: "Payout via provider reference {$providerReference}",
            );

            return true;
        }, attempts: 3);
    }

    /**
     * The provider confirmed it did not and will not move the money: release the hold.
     *
     * No ledger entry — nothing was earned, nothing was paid. The amount simply
     * returns to `available` and the next run picks it up naturally.
     */
    public function releaseAsFailed(PayoutItem $item, FailureClass $class, string $reason): bool
    {
        return $this->db()->transaction(function () use ($item, $class, $reason): bool {
            $wasHolding = $item->status->holdsReservation();

            $moved = $this->transitionFromAny(
                $item,
                [PayoutItemStatus::Pending, PayoutItemStatus::Submitted, PayoutItemStatus::Unknown],
                PayoutItemStatus::Failed,
                [
                    'failure_class' => $class->value,
                    'last_error' => $reason,
                    'settled_at' => now(),
                    'next_check_at' => null,
                ],
            );

            if (! $moved) {
                return false;
            }

            // Only release a hold that was actually placed. A 'pending' item never
            // reserved anything, and releasing it would invent money.
            if ($wasHolding) {
                $this->balances->applyDeltas(
                    instructorId: $item->instructor_id,
                    currency: $item->currency,
                    reservedDelta: -$item->amount_minor->minor,
                );
            }

            return true;
        }, attempts: 3);
    }

    /**
     * The outcome is genuinely unknown: keep the hold, schedule a status check.
     *
     * There is deliberately no path from here that resends. The only escape is
     * asking the provider.
     */
    public function markUnknown(PayoutItem $item, CarbonImmutable $checkAt, string $reason): bool
    {
        return $this->transitionFromAny(
            $item,
            [PayoutItemStatus::Submitted, PayoutItemStatus::Unknown],
            PayoutItemStatus::Unknown,
            [
                'last_error' => $reason,
                'next_check_at' => $checkAt,
            ],
        );
    }

    /** Push the next status check further out without changing state. */
    public function rescheduleCheck(PayoutItem $item, CarbonImmutable $checkAt): void
    {
        $this->db()->table('payout_items')
            ->where('id', $item->id)
            ->whereIn('status', [PayoutItemStatus::Submitted->value, PayoutItemStatus::Unknown->value])
            ->update(['next_check_at' => $checkAt, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
    }

    // ── The compare-and-swap primitive ──────────────────────────────────────

    /** @param array<string, mixed> $extra */
    private function transition(
        PayoutItem $item,
        PayoutItemStatus $from,
        PayoutItemStatus $to,
        array $extra = [],
    ): bool {
        return $this->transitionFromAny($item, [$from], $to, $extra);
    }

    /**
     * @param  non-empty-list<PayoutItemStatus>  $from
     * @param  array<string, mixed>  $extra
     */
    private function transitionFromAny(
        PayoutItem $item,
        array $from,
        PayoutItemStatus $to,
        array $extra = [],
    ): bool {
        foreach ($from as $source) {
            if ($source !== $to && ! $source->canTransitionTo($to)) {
                throw new \LogicException(
                    "Illegal payout transition {$source->value} → {$to->value}. The state machine in "
                    .PayoutItemStatus::class.' does not permit it.'
                );
            }
        }

        $affected = $this->db()->table('payout_items')
            ->where('id', $item->id)
            ->whereIn('status', array_map(fn (PayoutItemStatus $s): string => $s->value, $from))
            ->update([...$extra, 'status' => $to->value, 'updated_at' => now()]);

        if ($affected === 0) {
            // Not an error. Another worker already advanced this item, and stopping
            // here is exactly the behaviour that prevents a second payment.
            return false;
        }

        $item->refresh();

        return true;
    }
}
