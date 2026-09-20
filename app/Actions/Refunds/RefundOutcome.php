<?php

declare(strict_types=1);

namespace App\Actions\Refunds;

/**
 * The result of a refund attempt.
 */
enum RefundOutcome: string
{
    case Processed = 'processed';

    /** The same idempotency key was already handled. Nothing was done again. */
    case AlreadyProcessed = 'already_processed';
}
