<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * The only way anything is written to the ledger.
 *
 * ── The one behaviour that matters ───────────────────────────────────────────
 *
 * append() is idempotent on (type, source_type, source_id), and it is the DATABASE
 * that makes it so — a unique index, not a prior SELECT. An INSERT ... IGNORE that
 * affects zero rows means this exact entry already exists, and append() then
 * returns null WITHOUT touching the balance.
 *
 * That is the whole reason running the payout twice, retrying a crashed job, or
 * replaying a duplicated webhook cannot double-pay: the second attempt is a no-op
 * at the storage layer, and there is no window between "check" and "write" for a
 * concurrent worker to slip through.
 *
 * ── Why not check first ──────────────────────────────────────────────────────
 *
 *     if (! LedgerEntry::where(...)->exists()) { create(...) }   ← broken
 *
 * Two workers both pass the check, both insert, and one of them gets a duplicate
 * key error only if the index exists — at which point the index was doing the work
 * anyway. So the check is either redundant or wrong. We rely on the index.
 */
final readonly class LedgerWriter
{
    public function __construct(
        private InstructorBalanceRepository $balances,
        private ?ConnectionInterface $connection = null,
    ) {}

    private function db(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }

    /**
     * Append one entry and move the balance snapshot atomically.
     *
     * Returns whether anything was written. Deliberately not the row: an earlier
     * version re-selected the entry to return it, which is a wasted round trip on
     * every single earning — and no caller ever needed it. At 20 statements per
     * allocation, waste like that is the difference between minutes and hours on a
     * 500,000-subscription accrual run.
     *
     * @return bool true if the entry was written, false if it already existed (a replay)
     */
    public function append(
        int $instructorId,
        LedgerEntryType $type,
        Money $amount,
        string $sourceType,
        int $sourceId,
        ?CarbonImmutable $occurredAt = null,
        ?string $reason = null,
    ): bool {
        $this->assertSignMatchesType($type, $amount);

        if (! $this->db()->transactionLevel()) {
            throw new \LogicException(
                'LedgerWriter::append must run inside a transaction so that the entry and the '
                .'balance snapshot commit together. A committed entry with an unmoved balance is '
                .'exactly the drift the ledger exists to prevent.'
            );
        }

        $occurredAt ??= CarbonImmutable::now();

        // insertOrIgnore, so a duplicate (type, source) is silently skipped rather
        // than throwing. The affected-row count is the idempotency signal.
        $inserted = $this->db()->table('ledger_entries')->insertOrIgnore([
            'instructor_id' => $instructorId,
            'type' => $type->value,
            'amount_minor' => $amount->minor,
            'currency' => $amount->currency,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'occurred_at' => $occurredAt,
            'reason' => $reason,
            'created_at' => now(),
        ]);

        if ($inserted === 0) {
            // Already recorded. Returning here — before any balance movement — is
            // what makes a replay free of side effects.
            return false;
        }

        $deltas = $type->snapshotDeltas($amount->minor);

        $this->balances->applyDeltas(
            instructorId: $instructorId,
            currency: $amount->currency,
            earnedDelta: $deltas['earned'],
            paidDelta: $deltas['paid'],
            reservedDelta: $deltas['reserved'],
        );

        return true;
    }

    /**
     * A type declares the sign its amount must carry; a mismatch is a programming
     * error, not data to be tolerated. An `earning` of -500 would quietly reduce an
     * instructor's balance while reading as income in every report.
     */
    private function assertSignMatchesType(LedgerEntryType $type, Money $amount): void
    {
        $expected = $type->expectedSign();

        if ($expected === 0) {
            return;
        }

        if ($expected > 0 && $amount->minor < 0) {
            throw new \DomainException(
                "A {$type->value} entry must be positive, got {$amount->minor}."
            );
        }

        if ($expected < 0 && $amount->minor > 0) {
            throw new \DomainException(
                "A {$type->value} entry must be negative, got {$amount->minor}."
            );
        }
    }
}
