<?php

declare(strict_types=1);

namespace App\Actions\Allocation;

use App\Models\Allocation;

final readonly class AllocationResult
{
    private function __construct(
        public AllocationOutcome $outcome,
        public ?Allocation $allocation = null,
        public ?string $period = null,
        public ?string $reason = null,
    ) {}

    public static function allocated(Allocation $allocation): self
    {
        return new self(AllocationOutcome::Allocated, $allocation, $allocation->accrual_period);
    }

    public static function alreadyAllocated(Allocation $allocation): self
    {
        return new self(AllocationOutcome::AlreadyAllocated, $allocation, $allocation->accrual_period);
    }

    public static function skipped(string $period, string $reason): self
    {
        return new self(AllocationOutcome::Skipped, null, $period, $reason);
    }

    public function didWork(): bool
    {
        return $this->outcome === AllocationOutcome::Allocated;
    }

    public function wasReplay(): bool
    {
        return $this->outcome === AllocationOutcome::AlreadyAllocated;
    }
}
