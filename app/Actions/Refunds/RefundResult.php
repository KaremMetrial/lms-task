<?php

declare(strict_types=1);

namespace App\Actions\Refunds;

use App\Domain\Money\Money;
use App\Models\Refund;

final readonly class RefundResult
{
    private function __construct(
        public RefundOutcome $outcome,
        public ?Refund $refund = null,
        public ?Money $refundedToStudent = null,
        public ?Money $reclaimedFromInstructors = null,
        public int $allocationsVoided = 0,
        public int $allocationsAdjusted = 0,
    ) {}

    public static function processed(
        Refund $refund,
        Money $refundedToStudent,
        Money $reclaimedFromInstructors,
        int $voided,
        int $adjusted,
    ): self {
        return new self(
            RefundOutcome::Processed,
            $refund,
            $refundedToStudent,
            $reclaimedFromInstructors,
            $voided,
            $adjusted,
        );
    }

    public static function alreadyProcessed(Refund $refund): self
    {
        return new self(RefundOutcome::AlreadyProcessed, $refund);
    }

    /**
     * True when no instructor balance moved — the normal outcome for a pro-rata
     * refund, and the payoff for recognising revenue ratably.
     */
    public function touchedNoInstructor(): bool
    {
        return $this->reclaimedFromInstructors?->isZero() ?? true;
    }
}
