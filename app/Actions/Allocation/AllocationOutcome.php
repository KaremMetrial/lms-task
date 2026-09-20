<?php

declare(strict_types=1);

namespace App\Actions\Allocation;

/**
 * What an allocation attempt actually did.
 *
 * `AlreadyAllocated` is deliberately distinct from `Allocated`. Collapsing them
 * would make a replay indistinguishable from real work in logs and reports — and
 * "we processed 500,000 allocations" when 499,000 were no-ops is the kind of
 * reporting that hides a scheduling bug for months.
 */
enum AllocationOutcome: string
{
    case Allocated = 'allocated';
    case AlreadyAllocated = 'already_allocated';
    case Skipped = 'skipped';
}
