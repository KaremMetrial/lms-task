<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

/**
 * Every way an instructor's position can move.
 *
 * Each case also declares WHICH snapshot column it moves, so the mapping lives in
 * one enumerable place instead of being scattered across match() statements that
 * can silently disagree with each other.
 */
enum LedgerEntryType: string
{
    /** + an allocation line was recognised. */
    case Earning = 'earning';

    /** − money successfully left for the instructor. */
    case Payout = 'payout';

    /** − an earning undone before it was ever paid. */
    case Reversal = 'reversal';

    /** − already-paid money being recovered. Can drive a balance negative. */
    case Clawback = 'clawback';

    /** ± manual correction, always with a reason. */
    case Adjustment = 'adjustment';

    /** Whether the amount on this entry must be positive, negative, or either. */
    public function expectedSign(): int
    {
        return match ($this) {
            self::Earning => 1,
            self::Payout, self::Reversal, self::Clawback => -1,
            self::Adjustment => 0, // either
        };
    }

    /**
     * How this entry moves the balance snapshot, as deltas.
     *
     * A payout is the only type that touches two columns: the money leaves
     * (paid grows) and the hold placed at submit time is released (reserved
     * shrinks) — both in the same update, so `available` never flickers.
     *
     * @return array{earned: int, paid: int, reserved: int}
     */
    public function snapshotDeltas(int $amountMinor): array
    {
        return match ($this) {
            self::Earning,
            self::Reversal,
            self::Clawback,
            self::Adjustment => ['earned' => $amountMinor, 'paid' => 0, 'reserved' => 0],

            self::Payout => ['earned' => 0, 'paid' => -$amountMinor, 'reserved' => $amountMinor],
        };
    }
}
