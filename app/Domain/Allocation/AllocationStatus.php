<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

enum AllocationStatus: string
{
    case Allocated = 'allocated';

    /** A refund removed this period before it was earned. */
    case Voided = 'voided';
}
