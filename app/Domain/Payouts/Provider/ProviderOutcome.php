<?php

declare(strict_types=1);

namespace App\Domain\Payouts\Provider;

/**
 * What a provider call or status check actually told us.
 *
 * Note the distinction between Pending and NotFound — it is the difference between
 * "hold the money, we still do not know" and "it never arrived, it is safe to
 * release". Collapsing them would force a guess, and guessing is how you either
 * pay twice or lose an instructor's money.
 */
enum ProviderOutcome: string
{
    /** Money moved. Terminal. */
    case Succeeded = 'succeeded';

    /** The provider decided not to move it. Terminal. */
    case Failed = 'failed';

    /** Accepted, still processing. Outcome unknown — keep the reservation. */
    case Pending = 'pending';

    /**
     * The provider has no record of this key at all.
     *
     * Only meaningful from a status check, and only trustworthy because the key is
     * deterministic: if the provider had ever seen this payout, it would have seen
     * THIS key. So no record genuinely means nothing was moved.
     */
    case NotFound = 'not_found';
}
