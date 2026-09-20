<?php

declare(strict_types=1);

namespace App\Domain\Payouts;

/**
 * Why a payout attempt did not conclude, as a class rather than a message.
 *
 * Retry policy is a decision on typed data. Matching on error strings is how a
 * provider changing its wording turns into a double payment.
 */
enum FailureClass: string
{
    /** The provider rejected it and will keep rejecting it. Stop, surface it. */
    case Permanent = 'permanent';

    /** A retryable condition the provider confirmed it never processed. */
    case Transient = 'transient';

    /** Timeout or network error. The money may already have moved. Ask, never resend. */
    case Unknown = 'unknown';
}
