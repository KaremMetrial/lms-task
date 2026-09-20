<?php

declare(strict_types=1);

namespace App\Domain\Payouts;

enum PayoutBatchStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
